<?php

declare(strict_types=1);

use App\Shared\Referer\SearchEngine;

test('classify returns null for empty / null / unknown referers', function (): void {
    expect(SearchEngine::classify(null))->toBeNull();
    expect(SearchEngine::classify(''))->toBeNull();
    expect(SearchEngine::classify('https://example.com/foo'))->toBeNull();
    expect(SearchEngine::classify('https://t.me/some_chat'))->toBeNull();
});

test('classify recognises common search engines across TLDs and case', function (): void {
    expect(SearchEngine::classify('https://www.google.com/search?q=foo'))->toBe('google');
    expect(SearchEngine::classify('https://Google.co.uk/'))->toBe('google');
    expect(SearchEngine::classify('https://google.ru/'))->toBe('google');
    expect(SearchEngine::classify('https://www.bing.com/'))->toBe('bing');
    expect(SearchEngine::classify('https://www.baidu.com/'))->toBe('baidu');
    expect(SearchEngine::classify('https://yandex.ru/search/?text=x'))->toBe('yandex');
    expect(SearchEngine::classify('https://ya.ru/'))->toBe('yandex');
    expect(SearchEngine::classify('https://duckduckgo.com/?q=x'))->toBe('duckduckgo');
});

test('sqlFilter empty value is a no-op', function (): void {
    [$frag, $params] = SearchEngine::sqlFilter('', 'c.referer');
    expect($frag)->toBe('');
    expect($params)->toBe([]);
});

test('sqlFilter any returns OR of every pattern as ILIKE bind', function (): void {
    [$frag, $params] = SearchEngine::sqlFilter('any', 'c.referer', 'se');
    expect($frag)->toContain('c.referer ILIKE :se_0');
    expect($frag)->toStartWith('(');
    expect($frag)->toEndWith(')');
    expect(count($params))->toBeGreaterThan(5);
    foreach ($params as $v) {
        expect($v)->toStartWith('%');
        expect($v)->toEndWith('%');
    }
});

test('sqlFilter for a specific engine binds only that engine\'s patterns', function (): void {
    [$frag, $params] = SearchEngine::sqlFilter('bing', 'pe.referer', 'x');
    expect($frag)->toBe('(pe.referer ILIKE :x_0)');
    expect($params)->toBe(['x_0' => '%bing.com%']);
});

test('sqlFilter none excludes all engines but keeps non-empty referer rows', function (): void {
    [$frag, $params] = SearchEngine::sqlFilter('none', 'c.referer');
    expect($frag)->toContain('c.referer IS NOT NULL');
    expect($frag)->toContain("c.referer <> ''");
    expect($frag)->toContain('NOT (');
    expect($params)->not->toBeEmpty();
});

test('sqlFilter with unknown value is a safe no-op', function (): void {
    [$frag, $params] = SearchEngine::sqlFilter('not-a-real-engine', 'c.referer');
    expect($frag)->toBe('');
    expect($params)->toBe([]);
});

test('sqlFilterEngines ORs patterns of the given engines only', function (): void {
    [$frag, $params] = SearchEngine::sqlFilterEngines(['chatgpt', 'google'], 'pe.referer', 'se');
    expect($frag)->toStartWith('(')->toEndWith(')');
    expect($frag)->toContain('pe.referer ILIKE :se_0');
    // chatgpt has 2 patterns + google has 3 = 5 binds
    expect($params)->toHaveCount(5);
    expect($params['se_0'])->toBe('%chatgpt.com%');
});

test('classify recognises AI assistants and they are flagged by isAi', function (): void {
    $cases = [
        'https://chatgpt.com/share/abc'                 => 'chatgpt',
        'https://www.perplexity.ai/search?q=x'          => 'perplexity',
        'https://claude.ai/chat/123'                    => 'claude',
        'https://gemini.google.com/app/123'             => 'gemini',
        'https://copilot.microsoft.com/chats/abc'       => 'copilot',
        'https://chat.deepseek.com/'                    => 'deepseek',
        'https://grok.com/chat/123'                     => 'grok',
        'https://www.meta.ai/prompt/x'                  => 'meta_ai',
        'https://chat.mistral.ai/chat/123'              => 'mistral',
        'https://chat.qwen.ai/'                         => 'qwen',
        'https://kimi.com/chat/123'                     => 'kimi',
        'https://poe.com/ChatGPT'                       => 'poe',
        'https://you.com/search?q=x'                    => 'you',
        'https://notebooklm.google.com/notebook/123'    => 'notebooklm',
        'https://ya.ru/neuro?utm=x'                     => 'yandex_neuro',
    ];
    foreach ($cases as $referer => $engine) {
        expect(SearchEngine::classify($referer))->toBe($engine, $referer);
        expect(SearchEngine::isAi($engine))->toBeTrue($engine);
    }
});

test('AI engines win over the classic-engine host they live on', function (): void {
    // gemini/notebooklm subdomains must not be swallowed by 'google',
    // neuro/alice subdomains must not be swallowed by 'yandex'
    expect(SearchEngine::classify('https://gemini.google.com/app'))->toBe('gemini');
    expect(SearchEngine::classify('https://notebooklm.google.com/'))->toBe('notebooklm');
    expect(SearchEngine::classify('https://neuro.yandex.ru/'))->toBe('yandex_neuro');
    expect(SearchEngine::classify('https://alice.yandex.ru/'))->toBe('yandex_neuro');
});

test('isAi is false for classic engines, null and unknown keys', function (): void {
    expect(SearchEngine::isAi('google'))->toBeFalse();
    expect(SearchEngine::isAi('bing'))->toBeFalse();
    expect(SearchEngine::isAi('yandex'))->toBeFalse();
    expect(SearchEngine::isAi(null))->toBeFalse();
    expect(SearchEngine::isAi('not-a-real-engine'))->toBeFalse();
});

test('every AI_ENGINES key exists in ENGINES', function (): void {
    foreach (SearchEngine::AI_ENGINES as $key) {
        expect(SearchEngine::ENGINES)->toHaveKey($key);
    }
});

test('sqlFilterEngines is a no-op for empty / unknown engine lists', function (): void {
    expect(SearchEngine::sqlFilterEngines([], 'pe.referer'))->toBe(['', []]);
    expect(SearchEngine::sqlFilterEngines(['not-real'], 'pe.referer'))->toBe(['', []]);
});

test('click entry referer uses a nearby pixel and falls back to click referer', function (): void {
    $sql = SearchEngine::clickEntryRefererSql('ck');

    expect($sql)->toContain('pe.visitor_uuid = ck.visitor_uuid')
        ->toContain("ck.created_at - interval '24 hours'")
        ->toContain('ORDER BY pe.created_at DESC')
        ->toContain('), ck.referer)');
});

test('click entry referer rejects unsafe aliases', function (): void {
    expect(fn () => SearchEngine::clickEntryRefererSql('c; DROP TABLE x'))
        ->toThrow(InvalidArgumentException::class);
});

test('compact SQL filter evaluates a complex source expression once', function (): void {
    $source = SearchEngine::clickEntryRefererSql('c');
    [$sql, $params] = SearchEngine::sqlFilterCompact('any', $source, 'src');

    expect(substr_count($sql, 'SELECT pe.referer'))->toBe(1)
        ->and($sql)->toContain('LIKE ANY (ARRAY[')
        ->and($params)->not->toBeEmpty();
});
