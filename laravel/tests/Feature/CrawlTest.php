<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow;
use Tests\TestCase;

/** Every link a role can see must open without error, including every export link. */
class CrawlTest extends TestCase
{
    private const SKIP = ['/media/', '/accounts/logout', '/install'];

    protected function setUp(): void
    {
        parent::setUp();
        $inv = $this->income(billing: 'HOURLY', qty: '5', rate: '1200');
        $this->payment('2000', income: $inv);
        $this->payment('500', customer: $this->cust2);
        $this->income(amount: '900', vehicle: $this->tractor, customer: $this->cust2, user: $this->entry, status: 'PENDING');
        $this->expense('500');
        $this->expense('800', category: 'DRIVER', driver: $this->driver, dtype: 'ADVANCE', user: $this->entry, status: 'PENDING');
        $rejected = $this->expense('50', category: 'OTHER', vehicle: null, user: $this->entry, status: 'PENDING');
        Workflow::reject($rejected, $this->admin, 'Duplicate');
    }

    /** @return array{0: array<string,bool>, 1: list<string>, 2: int} */
    private function crawl(User $user, int $limit = 700): array
    {
        $this->login($user);
        $root = rtrim(url('/'), '/');
        $seen = [];
        $queue = ['/'];
        $problems = [];
        $exports = 0;
        while ($queue && count($seen) < $limit) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $resp = $this->get($url);
            $status = $resp->getStatusCode();
            if (str_contains($url, 'export=')) {
                $exports++;
                if ($status !== 200 || $resp->getContent() === '') {
                    $problems[] = "{$url} → {$status}";
                }
                continue;
            }
            if (! in_array($status, [200, 302], true)) {
                $problems[] = "{$url} → {$status}";
                continue;
            }
            if ($status === 302 || ! str_contains((string) $resp->headers->get('Content-Type'), 'text/html')) {
                continue;
            }
            preg_match_all('/href="([^"#]+)"/', $resp->getContent(), $m);
            foreach ($m[1] as $link) {
                $link = html_entity_decode($link);
                if (str_starts_with($link, $root)) {
                    $link = substr($link, strlen($root)) ?: '/';
                }
                if (! str_starts_with($link, '/') || str_starts_with($link, '//')) {
                    continue; // external (fonts) or javascript:
                }
                $path = parse_url($link, PHP_URL_PATH) ?? '/';
                foreach (self::SKIP as $skip) {
                    if (str_starts_with($path, $skip)) {
                        continue 2;
                    }
                }
                if (str_starts_with($path, '/img/') || str_starts_with($path, '/css/')) {
                    continue;
                }
                if (! isset($seen[$link])) {
                    $queue[] = $link;
                }
            }
        }

        return [$seen, $problems, $exports];
    }

    public function test_admin_can_open_every_link(): void
    {
        [$seen, $problems, $exports] = $this->crawl($this->admin);
        $this->assertSame([], $problems);
        $this->assertGreaterThan(60, count($seen));
        $this->assertGreaterThan(20, $exports);
    }

    public function test_entry_user_never_sees_forbidden_links(): void
    {
        [$seen, $problems] = $this->crawl($this->entry);
        $this->assertSame([], $problems);
        foreach (array_keys($seen) as $url) {
            $this->assertFalse(str_starts_with($url, '/settings') || str_starts_with($url, '/audit') || str_starts_with($url, '/accounts/users'), $url);
        }
    }

    public function test_viewer_never_sees_forbidden_links(): void
    {
        [$seen, $problems] = $this->crawl($this->viewer);
        $this->assertSame([], $problems);
        foreach (array_keys($seen) as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            $this->assertFalse(str_ends_with($path, '/new') || str_ends_with($path, '/edit') || str_ends_with($path, '/delete'), $url);
        }
    }
}
