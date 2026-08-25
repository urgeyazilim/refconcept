<?php

declare(strict_types=1);

use App\Support\Text\TurkishText;

/**
 * Case-folding that survives Turkish.
 *
 * Every assertion here is a bug that shipped before this class existed, or would have.
 * Unicode's default lowercasing turns "İ" into an i followed by a combining dot — two
 * characters that look like one — and the damage is silent: a spreadsheet column headed
 * "İndirimli fiyat" matched no alias, so the discount prices simply never arrived and
 * nothing reported a problem.
 */
beforeEach(function (): void {
    $this->text = new TurkishText;
});

it('lowercases the dotted capital to a plain i', function (): void {
    // mb_strtolower alone produces "i" + U+0307 here, which is the whole problem.
    expect($this->text->lower('İSİM'))->toBe('isim')
        ->and(mb_strlen($this->text->lower('İ')))->toBe(1);
});

it('lowercases the dotless capital to a dotless i', function (): void {
    // The other half of the pair, which default rules leave as "i" — wrong in Turkish,
    // where I and İ are different letters.
    expect($this->text->lower('IŞIK'))->toBe('ışık');
});

it('folds a header to something an alias can match', function (): void {
    expect($this->text->fold('İndirimli Fiyat'))->toBe('indirimli fiyat')
        ->and($this->text->fold('INDIRIMLI FIYAT'))->toBe('indirimli fiyat')
        ->and($this->text->fold('indirimli fiyat'))->toBe('indirimli fiyat');
});

it('gives the same answer whichever keyboard the seller used', function (): void {
    // A seller who typed their headers without the Turkish keyboard should not have to
    // remap every column.
    expect($this->text->fold('Genişlik'))->toBe($this->text->fold('genislik'))
        ->and($this->text->fold('Ürün Adı'))->toBe('urun adi')
        ->and($this->text->fold('ÇEKMECE'))->toBe('cekmece');
});

it('collapses punctuation and spacing', function (): void {
    expect($this->text->fold('  Ürün   Adı / Başlık  '))->toBe('urun adi baslik')
        ->and($this->text->fold('Stok (adet)'))->toBe('stok adet');
});

it('folds with a chosen separator', function (): void {
    // Used for slug-like keys, where "-" reads better than a space.
    expect($this->text->fold('Açık Gri', '-'))->toBe('acik-gri')
        ->and($this->text->fold('  İki  Kelime  ', '-'))->toBe('iki-kelime');
});

it('leaves no combining marks behind', function (): void {
    /*
     * The failure mode this class exists for: the combining dot survives lowercasing,
     * gets stripped as punctuation, and splits one word into two — so "İsim" arrives as
     * "i sim" and matches nothing.
     */
    expect($this->text->fold('İsim'))->toBe('isim')
        ->and($this->text->fold('İsim'))->not->toContain(' ');
});
