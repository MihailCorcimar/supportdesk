<?php

namespace App\Support;

class AvatarProfile
{
    /**
     * Return deterministic avatar path for a name.
     */
    public static function defaultAvatarPathForName(string $name, int $variantsPerGender = 12): string
    {
        $variantsPerGender = max(1, $variantsPerGender);
        $gender = self::isLikelyFemale($name) ? 'female' : 'male';
        $seed = mb_strtolower(trim($name));
        $hash = abs((int) crc32($seed));
        $index = ($hash % $variantsPerGender) + 1;

        return sprintf('avatars/default-%s-%02d.png', $gender, $index);
    }

    /**
     * Heuristic by title/first name without storing gender in DB.
     */
    public static function isLikelyFemale(string $name): bool
    {
        $tokens = preg_split('/\s+/', trim($name)) ?: [];
        $normalized = array_values(array_filter(array_map(
            static fn (string $token): string => strtolower(\Illuminate\Support\Str::ascii(trim($token, '.,'))),
            $tokens
        )));

        $title = $normalized[0] ?? '';
        $firstName = '';
        foreach ($normalized as $token) {
            if (in_array($token, ['dr', 'mr', 'mrs', 'ms', 'miss', 'sr', 'sra', 'dra', 'prof'], true)) {
                continue;
            }

            $firstName = $token;
            break;
        }

        if ($firstName === '') {
            $firstName = $normalized[0] ?? '';
        }

        $maleTitles = ['mr', 'sr'];
        $femaleTitles = ['mrs', 'ms', 'miss', 'sra', 'dra'];

        if (in_array($title, $maleTitles, true)) {
            return false;
        }

        if (in_array($title, $femaleTitles, true)) {
            return true;
        }

        $maleFirst = [
            'aldo', 'alexandre', 'andre', 'andy', 'arlo', 'axel', 'brendon', 'bruno', 'cade', 'cesar',
            'emanuel', 'emerald', 'estevan', 'flavio', 'goncalo', 'hector', 'joao', 'jorge', 'junius',
            'lemuel', 'mihail', 'mike', 'nuno', 'operador', 'romaine', 'salvador', 'ulises',
        ];
        $femaleFirst = [
            'alda', 'alice', 'alysha', 'amy', 'andreanne', 'ariana', 'camila', 'chelsea', 'constanca',
            'debora', 'diana', 'dorothy', 'fabiana', 'francisca', 'helena', 'jacky', 'jordane', 'karianne',
            'kelly', 'lara', 'leonie', 'lilyan', 'luisa', 'mafalda', 'matilde', 'mckenna', 'monica', 'montana',
            'nikita', 'patricia', 'yessenia',
        ];

        if (in_array($firstName, $maleFirst, true)) {
            return false;
        }

        if (in_array($firstName, $femaleFirst, true)) {
            return true;
        }

        return str_ends_with($firstName, 'a');
    }
}
