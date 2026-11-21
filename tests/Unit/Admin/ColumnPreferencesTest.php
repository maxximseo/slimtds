<?php

declare(strict_types=1);

use App\Admin\Clicks\ColumnPreferences;

beforeEach(function (): void {
    $_SESSION = [];
});

afterEach(function (): void {
    $_SESSION = [];
});

test('click page is visible by default next to the offer', function (): void {
    $columns = (new ColumnPreferences())->visible();
    $offer = array_search('offer', $columns, true);

    expect($columns[$offer + 1] ?? null)->toBe('referer');
});

test('click page is added once to an existing saved column layout', function (): void {
    $prefs = new ColumnPreferences();
    $prefs->setVisible(['time', 'offer', 'status']);
    unset($_SESSION['clicks.columns.source_page_added']);

    expect($prefs->visible())->toBe(['time', 'offer', 'referer', 'status'])
        ->and($prefs->visible())->toBe(['time', 'offer', 'referer', 'status']);
});

test('an operator can still hide the click page after the one-time addition', function (): void {
    $prefs = new ColumnPreferences();
    $prefs->setVisible(['time', 'status']);

    expect($prefs->visible())->toBe(['time', 'status']);
});

test('resetting columns restores the click page', function (): void {
    $prefs = new ColumnPreferences();
    $prefs->setVisible(['time', 'status']);
    $prefs->reset();

    expect($prefs->visible())->toContain('referer');
});
