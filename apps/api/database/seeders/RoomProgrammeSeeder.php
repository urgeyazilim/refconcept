<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The questions each room is designed by.
 *
 * Everything a customer is asked, in one file, because it is a single editorial decision
 * rather than ten. Reading it top to bottom should tell you what designing a home in this
 * product feels like, and if a question reads badly here it reads badly on the screen.
 *
 * Every option names real catalogue slugs. That is the discipline the whole thing rests on:
 * an option that asks for a category nobody stocks is an option that will be hidden by the
 * coverage check, and an option that asks for a category that does not exist is a bug this
 * seeder's own test will catch. The alternative — the model inventing a programme — is what
 * put a television unit, curtains, a picture and a plant into a customer's rendered room
 * beside a shopping list of four items.
 *
 * Re-runnable. Re-seeding rewrites version 1 in place rather than stacking versions, so a
 * deploy that fixes a typo does not orphan every design that answered the old wording;
 * publishing a genuinely different set of questions means bumping the version by hand.
 */
final class RoomProgrammeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->programmes() as $roomType => $programme) {
            $this->seedProgramme((string) $roomType, $programme);
        }

        $this->command?->info(sprintf(
            'Room programmes: %d rooms, %d questions, %d options.',
            DB::table('room_programmes')->count(),
            DB::table('programme_questions')->count(),
            DB::table('programme_options')->count(),
        ));
    }

    /**
     * Every room in a home, and what to ask about it.
     *
     * The counts are deliberate. Eight questions for a living room, four for a hallway —
     * the number should match how much there is to decide, not a template. A wizard that
     * asks eight questions about a balcony is a wizard people abandon on the balcony.
     *
     * `none` marks the option that asks for nothing: "şimdilik istemiyorum" is a real
     * answer and a different thing from an unanswered question.
     *
     * @return array<string, array{name: string, questions: array<int, array<string, mixed>>}>
     */
    private function programmes(): array
    {
        return [
            'living_room' => [
                'name' => 'Salon',
                'questions' => [
                    [
                        'code' => 'seating',
                        'prompt' => 'Oturma grubu nasıl olsun?',
                        'help' => 'Odanın ana parçası; gerisi buna göre yerleşir.',
                        'options' => [
                            ['three-seater', 'Üçlü kanepe', 'sofa-three', null, [['kanepe', 1, true]], true],
                            ['corner', 'Köşe koltuk', 'sofa-corner', 2_600, [['oturma-grubu', 1, true]]],
                            ['two-plus-chairs', 'İkili kanepe + iki berjer', 'sofa-two-chairs', null, [['kanepe', 1, true], ['koltuk', 2, false]]],
                            ['none', 'Şimdilik istemiyorum', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'coffee-table',
                        'prompt' => 'Sehpa ister misiniz?',
                        'options' => [
                            ['center', 'Orta sehpa', 'table-coffee', null, [['sehpa', 1, true]], true],
                            ['center-and-side', 'Orta ve yan sehpa', 'table-coffee-side', null, [['sehpa', 2, true]]],
                            ['side-only', 'Sadece yan sehpa', 'table-side', null, [['sehpa', 1, true]]],
                            ['none', 'İstemiyorum', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'tv-unit',
                        'prompt' => 'Televizyon ünitesi olsun mu?',
                        'options' => [
                            ['yes', 'Evet', 'tv', null, [['tv-unitesi', 1, true]]],
                            ['no', 'Hayır', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'rug',
                        'prompt' => 'Halı olsun mu?',
                        'help' => 'Oturma grubunun ön ayakları halının üzerinde durur.',
                        'options' => [
                            ['large', 'Evet, büyük', 'rug-large', null, [['hali', 1, true]], true],
                            ['medium', 'Evet, orta boy', 'rug-medium', null, [['hali', 1, true]]],
                            ['none', 'Hayır', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'lighting',
                        'prompt' => 'Aydınlatma',
                        'options' => [
                            ['pendant', 'Tavan sarkıtı', 'light-pendant', null, [['tavan-aydinlatma', 1, true]], true],
                            ['floor', 'Lambader', 'light-floor', null, [['lambader', 1, true]]],
                            ['both', 'İkisi de', 'light-both', null, [['tavan-aydinlatma', 1, true], ['lambader', 1, false]]],
                            ['none', 'Mevcut aydınlatma kalsın', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'storage',
                        'prompt' => 'Depolama ister misiniz?',
                        'options' => [
                            ['bookcase', 'Kitaplık', 'bookcase', null, [['kitaplik', 1, true]]],
                            ['console', 'Konsol', 'console', null, [['konsol', 1, true]]],
                            ['both', 'İkisi de', 'storage-both', null, [['kitaplik', 1, true], ['konsol', 1, false]]],
                            ['none', 'İstemiyorum', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'curtain',
                        'prompt' => 'Perde olsun mu?',
                        'options' => [
                            ['yes', 'Evet', 'curtain', null, [['perde', 1, true]]],
                            ['no', 'Hayır, mevcut kalsın', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'decor',
                        'prompt' => 'Son dokunuşlar',
                        'help' => 'Birden fazla seçebilirsiniz.',
                        'kind' => 'multi',
                        'required' => false,
                        'options' => [
                            ['art', 'Tablo', 'art', null, [['tablo', 1, false]]],
                            ['plant', 'Bitki', 'plant', null, [['bitki', 1, false]]],
                            ['vase', 'Vazo', 'vase', null, [['vazo', 1, false]]],
                            ['cushions', 'Kırlent', 'cushion', null, [['kirlent', 4, false]]],
                        ],
                    ],
                ],
            ],

            'bedroom' => [
                'name' => 'Yatak Odası',
                'questions' => [
                    [
                        'code' => 'bed',
                        'prompt' => 'Yatak ölçüsü',
                        'options' => [
                            ['double', 'Çift kişilik', 'bed-double', 1_800, [['yatak', 1, true]], true],
                            ['king', 'King (geniş)', 'bed-king', 2_100, [['yatak', 1, true]]],
                            ['single', 'Tek kişilik', 'bed-single', 1_200, [['yatak', 1, true]]],
                        ],
                    ],
                    [
                        'code' => 'wardrobe',
                        'prompt' => 'Gardırop ister misiniz?',
                        'options' => [
                            ['yes', 'Evet', 'wardrobe', 1_800, [['gardirop', 1, true]], true],
                            ['no', 'Hayır, mevcut kalsın', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'nightstand',
                        'prompt' => 'Komodin',
                        'options' => [
                            ['pair', 'İki adet', 'nightstand-pair', null, [['komodin', 2, true]], true],
                            ['single', 'Bir adet', 'nightstand', null, [['komodin', 1, true]]],
                            ['none', 'İstemiyorum', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'bedding',
                        'prompt' => 'Nevresim takımı ister misiniz?',
                        'options' => [
                            ['yes', 'Evet', 'bedding', null, [['nevresim', 1, true]]],
                            ['no', 'Hayır', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'lighting',
                        'prompt' => 'Aydınlatma',
                        'options' => [
                            ['pendant', 'Tavan sarkıtı', 'light-pendant', null, [['tavan-aydinlatma', 1, true]]],
                            ['table', 'Komodin lambası', 'light-table', null, [['masa-lambasi', 2, true]], true],
                            ['none', 'Mevcut kalsın', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'rug',
                        'prompt' => 'Halı olsun mu?',
                        'options' => [
                            ['yes', 'Evet', 'rug-large', null, [['hali', 1, true]]],
                            ['no', 'Hayır', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'curtain',
                        'prompt' => 'Perde olsun mu?',
                        'help' => 'Yatak odasında karartma perdesi uykuyu belirgin biçimde iyileştirir.',
                        'options' => [
                            ['yes', 'Evet', 'curtain', null, [['perde', 1, true]]],
                            ['no', 'Hayır, mevcut kalsın', 'skip', null, [], true, true],
                        ],
                    ],
                ],
            ],

            'kids_room' => [
                'name' => 'Çocuk Odası',
                'questions' => [
                    [
                        'code' => 'age',
                        'prompt' => 'Çocuğun yaşı',
                        'help' => 'Mobilya ölçüleri ve yükseklikleri buna göre seçilir.',
                        'options' => [
                            ['toddler', '0-3 yaş', 'age-toddler', null, []],
                            ['child', '4-9 yaş', 'age-child', null, [], true],
                            ['teen', '10 yaş ve üzeri', 'age-teen', null, []],
                        ],
                    ],
                    [
                        'code' => 'bed',
                        'prompt' => 'Yatak tipi',
                        'options' => [
                            ['single', 'Tek kişilik', 'bed-single', 1_200, [['yatak', 1, true]], true],
                            ['bunk', 'Ranza', 'bed-bunk', 2_000, [['yatak', 1, true]]],
                        ],
                    ],
                    [
                        'code' => 'desk',
                        'prompt' => 'Çalışma masası ister misiniz?',
                        'options' => [
                            ['yes', 'Evet', 'desk', 1_200, [['yemek-masasi', 1, true], ['sandalye', 1, false]]],
                            ['no', 'Hayır', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'storage',
                        'prompt' => 'Depolama',
                        'options' => [
                            ['wardrobe', 'Gardırop', 'wardrobe', 1_500, [['gardirop', 1, true]], true],
                            ['shelves', 'Kitaplık / raf', 'bookcase', null, [['kitaplik', 1, true]]],
                            ['both', 'İkisi de', 'storage-both', 1_500, [['gardirop', 1, true], ['kitaplik', 1, false]]],
                        ],
                    ],
                    [
                        'code' => 'rug',
                        'prompt' => 'Halı olsun mu?',
                        'help' => 'Oyun alanı için yumuşak bir zemin.',
                        'options' => [
                            ['yes', 'Evet', 'rug-large', null, [['hali', 1, true]], true],
                            ['no', 'Hayır', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'lighting',
                        'prompt' => 'Aydınlatma',
                        'options' => [
                            ['pendant', 'Tavan aydınlatması', 'light-pendant', null, [['tavan-aydinlatma', 1, true]], true],
                            ['desk', 'Masa lambası', 'light-table', null, [['masa-lambasi', 1, true]]],
                            ['none', 'Mevcut kalsın', 'skip', null, [], false, true],
                        ],
                    ],
                ],
            ],

            'dining_room' => [
                'name' => 'Yemek Odası',
                'questions' => [
                    [
                        'code' => 'table',
                        'prompt' => 'Masa kaç kişilik olsun?',
                        'options' => [
                            ['four', '4 kişilik', 'table-4', 1_200, [['yemek-masasi', 1, true], ['sandalye', 4, true]]],
                            ['six', '6 kişilik', 'table-6', 1_800, [['yemek-masasi', 1, true], ['sandalye', 6, true]], true],
                            ['eight', '8 kişilik', 'table-8', 2_400, [['yemek-masasi', 1, true], ['sandalye', 8, true]]],
                        ],
                    ],
                    [
                        'code' => 'storage',
                        'prompt' => 'Konsol veya vitrin ister misiniz?',
                        'options' => [
                            ['console', 'Konsol', 'console', null, [['konsol', 1, true]]],
                            ['none', 'İstemiyorum', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'lighting',
                        'prompt' => 'Aydınlatma',
                        'help' => 'Sarkıt, masanın tam ortasına ve yaklaşık 75 cm yukarısına gelir.',
                        'options' => [
                            ['pendant', 'Masa üstü sarkıt', 'light-pendant', null, [['tavan-aydinlatma', 1, true]], true],
                            ['none', 'Mevcut kalsın', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'rug',
                        'prompt' => 'Halı olsun mu?',
                        'help' => 'Sandalyeler geri çekildiğinde de halının üzerinde kalmalıdır.',
                        'options' => [
                            ['yes', 'Evet', 'rug-large', null, [['hali', 1, true]]],
                            ['no', 'Hayır', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'mirror',
                        'prompt' => 'Ayna ister misiniz?',
                        'options' => [
                            ['yes', 'Evet', 'mirror', null, [['ayna', 1, false]]],
                            ['no', 'Hayır', 'skip', null, [], true, true],
                        ],
                    ],
                ],
            ],

            'kitchen' => [
                'name' => 'Mutfak',
                'questions' => [
                    [
                        'code' => 'cabinets',
                        'prompt' => 'Dolap düzeni',
                        'options' => [
                            ['replace', 'Dolaplar yenilensin', 'cabinet', null, [['mutfak-dolabi', 1, true]], true],
                            ['keep', 'Mevcut dolaplar kalsın', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'worktop',
                        'prompt' => 'Tezgah',
                        'options' => [
                            ['replace', 'Tezgah yenilensin', 'worktop', null, [['tezgah', 1, true]]],
                            ['keep', 'Mevcut tezgah kalsın', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'island',
                        'prompt' => 'Ada veya bar olsun mu?',
                        'help' => 'Ada için çevresinde en az 90 cm dolaşım alanı gerekir.',
                        'options' => [
                            ['yes', 'Evet', 'island', 2_400, [['tezgah', 1, true], ['bar-taburesi', 2, false]]],
                            ['no', 'Hayır', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'stools',
                        'prompt' => 'Bar taburesi',
                        'options' => [
                            ['two', 'İki adet', 'stool-2', null, [['bar-taburesi', 2, true]]],
                            ['four', 'Dört adet', 'stool-4', null, [['bar-taburesi', 4, true]]],
                            ['none', 'İstemiyorum', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'lighting',
                        'prompt' => 'Aydınlatma',
                        'options' => [
                            ['pendant', 'Tezgah üstü sarkıt', 'light-pendant', null, [['tavan-aydinlatma', 1, true]]],
                            ['none', 'Mevcut kalsın', 'skip', null, [], true, true],
                        ],
                    ],
                ],
            ],

            'bathroom' => [
                'name' => 'Banyo',
                'questions' => [
                    [
                        'code' => 'vanity',
                        'prompt' => 'Lavabo ve dolap',
                        'options' => [
                            ['both', 'Lavabo ve dolap birlikte', 'vanity', 800, [['lavabo', 1, true], ['banyo-dolabi', 1, true]], true],
                            ['basin', 'Sadece lavabo', 'basin', 600, [['lavabo', 1, true]]],
                            ['keep', 'Mevcut kalsın', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'mirror',
                        'prompt' => 'Ayna ister misiniz?',
                        'options' => [
                            ['yes', 'Evet', 'mirror', null, [['ayna', 1, true]], true],
                            ['no', 'Hayır', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'storage',
                        'prompt' => 'Ek depolama ister misiniz?',
                        'options' => [
                            ['yes', 'Evet', 'cabinet', null, [['banyo-dolabi', 1, true]]],
                            ['no', 'Hayır', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'accessories',
                        'prompt' => 'Aksesuar seti',
                        'help' => 'Havluluk, sabunluk, çöp kovası.',
                        'options' => [
                            ['yes', 'Evet', 'accessories', null, [['banyo-aksesuar', 1, false]]],
                            ['no', 'Hayır', 'skip', null, [], true, true],
                        ],
                    ],
                ],
            ],

            'office' => [
                'name' => 'Çalışma Odası',
                'questions' => [
                    [
                        'code' => 'desk',
                        'prompt' => 'Masa',
                        'options' => [
                            ['single', 'Tek kişilik', 'desk', 1_200, [['yemek-masasi', 1, true]], true],
                            ['double', 'İki kişilik', 'desk-double', 1_800, [['yemek-masasi', 1, true]]],
                        ],
                    ],
                    [
                        'code' => 'chair',
                        'prompt' => 'Sandalye',
                        'options' => [
                            ['one', 'Bir adet', 'chair', null, [['sandalye', 1, true]], true],
                            ['two', 'İki adet', 'chair-2', null, [['sandalye', 2, true]]],
                        ],
                    ],
                    [
                        'code' => 'bookcase',
                        'prompt' => 'Kitaplık ister misiniz?',
                        'options' => [
                            ['yes', 'Evet', 'bookcase', 800, [['kitaplik', 1, true]], true],
                            ['no', 'Hayır', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'seating',
                        'prompt' => 'Ek oturma ister misiniz?',
                        'help' => 'Okuma köşesi veya misafir için.',
                        'options' => [
                            ['armchair', 'Berjer', 'armchair', null, [['koltuk', 1, true]]],
                            ['none', 'İstemiyorum', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'lighting',
                        'prompt' => 'Aydınlatma',
                        'options' => [
                            ['desk', 'Masa lambası', 'light-table', null, [['masa-lambasi', 1, true]], true],
                            ['floor', 'Lambader', 'light-floor', null, [['lambader', 1, true]]],
                            ['none', 'Mevcut kalsın', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'rug',
                        'prompt' => 'Halı olsun mu?',
                        'options' => [
                            ['yes', 'Evet', 'rug-medium', null, [['hali', 1, true]]],
                            ['no', 'Hayır', 'skip', null, [], true, true],
                        ],
                    ],
                ],
            ],

            'hallway' => [
                'name' => 'Hol',
                'questions' => [
                    [
                        'code' => 'console',
                        'prompt' => 'Konsol ister misiniz?',
                        'help' => 'Anahtar, çanta ve postalar için.',
                        'options' => [
                            ['yes', 'Evet', 'console', 800, [['konsol', 1, true]], true],
                            ['no', 'Hayır', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'mirror',
                        'prompt' => 'Ayna ister misiniz?',
                        'help' => 'Dar bir holü belirgin biçimde genişletir.',
                        'options' => [
                            ['yes', 'Evet', 'mirror', null, [['ayna', 1, true]], true],
                            ['no', 'Hayır', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'seating',
                        'prompt' => 'Oturma ister misiniz?',
                        'help' => 'Ayakkabı giyerken.',
                        'options' => [
                            ['pouffe', 'Puf', 'pouffe', null, [['puf', 1, true]]],
                            ['none', 'İstemiyorum', 'skip', null, [], true, true],
                        ],
                    ],
                    [
                        'code' => 'lighting',
                        'prompt' => 'Aydınlatma',
                        'options' => [
                            ['wall', 'Duvar aydınlatması', 'light-wall', null, [['duvar-aydinlatma', 1, true]]],
                            ['none', 'Mevcut kalsın', 'skip', null, [], true, true],
                        ],
                    ],
                ],
            ],

            'balcony' => [
                'name' => 'Balkon',
                'questions' => [
                    [
                        'code' => 'seating',
                        'prompt' => 'Oturma',
                        'options' => [
                            ['two-chairs', 'İki sandalye', 'chair-2', 1_200, [['sandalye', 2, true]], true],
                            ['bench', 'Küçük kanepe', 'sofa-two-chairs', 1_600, [['kanepe', 1, true]]],
                            ['pouffe', 'Puf', 'pouffe', null, [['puf', 2, true]]],
                        ],
                    ],
                    [
                        'code' => 'table',
                        'prompt' => 'Masa ister misiniz?',
                        'options' => [
                            ['small', 'Küçük masa', 'table-side', 600, [['sehpa', 1, true]], true],
                            ['none', 'İstemiyorum', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'plants',
                        'prompt' => 'Bitki ister misiniz?',
                        'options' => [
                            ['yes', 'Evet', 'plant', null, [['bitki', 2, false]], true],
                            ['no', 'Hayır', 'skip', null, [], false, true],
                        ],
                    ],
                    [
                        'code' => 'lighting',
                        'prompt' => 'Aydınlatma',
                        'options' => [
                            ['wall', 'Duvar aydınlatması', 'light-wall', null, [['duvar-aydinlatma', 1, true]]],
                            ['none', 'Mevcut kalsın', 'skip', null, [], true, true],
                        ],
                    ],
                ],
            ],

            'other' => [
                'name' => 'Diğer',
                'questions' => [
                    [
                        'code' => 'furniture',
                        'prompt' => 'Odada ne olsun?',
                        'help' => 'Birden fazla seçebilirsiniz.',
                        'kind' => 'multi',
                        // Not required: it is the only question this room asks, and a
                        // customer who wants a bare room should be able to say so by
                        // choosing nothing rather than being held on the last screen.
                        'required' => false,
                        'options' => [
                            ['seating', 'Oturma', 'sofa-three', null, [['kanepe', 1, false]]],
                            ['table', 'Masa', 'table-6', null, [['yemek-masasi', 1, false]]],
                            ['storage', 'Depolama', 'bookcase', null, [['kitaplik', 1, false]]],
                            ['rug', 'Halı', 'rug-medium', null, [['hali', 1, false]]],
                            ['lighting', 'Aydınlatma', 'light-pendant', null, [['tavan-aydinlatma', 1, false]]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array{name: string, questions: array<int, array<string, mixed>>}  $programme
     */
    private function seedProgramme(string $roomType, array $programme): void
    {
        $id = DB::table('room_programmes')
            ->where('room_type', $roomType)
            ->where('version', 1)
            ->value('id') ?? (string) Str::uuid7();

        DB::table('room_programmes')->updateOrInsert(
            ['room_type' => $roomType, 'version' => 1],
            [
                'id' => $id,
                'status' => 'published',
                'name' => $programme['name'],
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        /*
         * Questions are replaced wholesale rather than merged.
         *
         * A question removed from this file has to leave the database, and updateOrInsert
         * alone would keep it for ever — asking customers something the editorial decision
         * dropped. The cascade takes its options and their categories with it. Answers are
         * unaffected: they live on the design and are keyed by code.
         */
        DB::table('programme_questions')->where('programme_id', $id)->delete();

        foreach ($programme['questions'] as $position => $question) {
            $this->seedQuestion($id, (int) $position, $question);
        }
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function seedQuestion(string $programmeId, int $position, array $question): void
    {
        $questionId = (string) Str::uuid7();

        DB::table('programme_questions')->insert([
            'id' => $questionId,
            'programme_id' => $programmeId,
            'code' => $question['code'],
            'prompt' => $question['prompt'],
            'help' => $question['help'] ?? null,
            'kind' => $question['kind'] ?? 'single',
            'is_required' => $question['required'] ?? true,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var array<int, array<int, mixed>> $options */
        $options = $question['options'];

        foreach ($options as $index => $option) {
            [$code, $label, $icon, $minWall, $categories] = [
                $option[0], $option[1], $option[2], $option[3], $option[4],
            ];

            $optionId = (string) Str::uuid7();

            DB::table('programme_options')->insert([
                'id' => $optionId,
                'question_id' => $questionId,
                'code' => $code,
                'label' => $label,
                'icon' => $icon,
                'min_wall_mm' => $minWall,
                'is_default' => (bool) ($option[5] ?? false),
                'is_none' => (bool) ($option[6] ?? false),
                'position' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /** @var array<int, array{0: string, 1: int, 2: bool}> $categories */
            foreach ($categories as $categoryIndex => [$slug, $quantity, $required]) {
                DB::table('programme_option_categories')->insert([
                    'id' => (string) Str::uuid7(),
                    'option_id' => $optionId,
                    'category_slug' => $slug,
                    'quantity' => $quantity,
                    'is_required' => $required,
                    'position' => $categoryIndex,
                ]);
            }
        }
    }
}
