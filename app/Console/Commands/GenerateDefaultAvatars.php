<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AvatarProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateDefaultAvatars extends Command
{
    protected $signature = 'supportdesk:generate-default-avatars {--size=256 : Avatar size in pixels} {--variants=12 : Variants per gender} {--assign-users : Re-assign generated defaults to all users}';

    protected $description = 'Generate varied default avatar PNGs for male and female profiles';

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('GD extension is required.');
            return self::FAILURE;
        }

        $size = max(128, (int) $this->option('size'));
        $variants = max(4, min(30, (int) $this->option('variants')));

        $basePath = storage_path('app/public/avatars');
        File::ensureDirectoryExists($basePath);

        foreach (['male', 'female'] as $gender) {
            for ($i = 1; $i <= $variants; $i++) {
                $image = imagecreatetruecolor($size, $size);
                imagealphablending($image, true);
                imagesavealpha($image, true);

                $this->drawAvatar($image, $size, $gender, $i);

                $file = sprintf('%s/default-%s-%02d.png', $basePath, $gender, $i);
                imagepng($image, $file, 8);
                imagedestroy($image);
            }

            $first = sprintf('%s/default-%s-%02d.png', $basePath, $gender, 1);
            $single = sprintf('%s/default-%s.png', $basePath, $gender);
            if (is_file($first)) {
                copy($first, $single);
            }
        }

        if ((bool) $this->option('assign-users')) {
            User::query()->chunkById(200, function ($users) use ($variants): void {
                foreach ($users as $user) {
                    $user->forceFill([
                        'avatar_path' => AvatarProfile::defaultAvatarPathForName((string) $user->name, $variants),
                    ])->save();
                }
            });
        }

        $this->info("Generated {$variants} avatars per gender at: {$basePath}");

        return self::SUCCESS;
    }

    private function drawAvatar($image, int $size, string $gender, int $variant): void
    {
        $seed = crc32("{$gender}-{$variant}");
        mt_srand($seed);

        $bgPalette = [
            [[246, 250, 255], [225, 239, 255]],
            [[250, 247, 255], [234, 227, 252]],
            [[245, 255, 252], [220, 247, 238]],
            [[255, 248, 242], [255, 232, 217]],
            [[247, 248, 252], [227, 231, 241]],
        ];

        $skinPalette = [
            [253, 224, 196],
            [241, 194, 152],
            [226, 177, 136],
            [197, 145, 106],
            [152, 103, 74],
        ];

        $hairPalette = [
            [35, 38, 44],
            [66, 47, 35],
            [91, 63, 43],
            [117, 89, 60],
            [178, 140, 95],
            [25, 25, 31],
            [110, 112, 119],
        ];

        $eyePalette = [
            [56, 65, 84],
            [34, 79, 63],
            [71, 47, 33],
            [42, 56, 105],
        ];

        $accentPalette = [
            [15, 134, 104],
            [48, 108, 190],
            [166, 87, 56],
            [113, 80, 170],
            [34, 129, 141],
        ];

        [$bgA, $bgB] = $bgPalette[$variant % count($bgPalette)];
        $this->fillVerticalGradient($image, $size, $size, $bgA, $bgB);

        $skin = $skinPalette[$variant % count($skinPalette)];
        $hair = $hairPalette[($variant + 1) % count($hairPalette)];
        $eyes = $eyePalette[($variant + 2) % count($eyePalette)];
        $accent = $accentPalette[($variant + 3) % count($accentPalette)];

        $shadow = $this->c($image, 0, 0, 0, 24);
        imagefilledellipse($image, (int) ($size * 0.5), (int) ($size * 0.88), (int) ($size * 0.54), (int) ($size * 0.14), $shadow);

        $shirtColor = $this->c($image, $accent[0], $accent[1], $accent[2]);
        imagefilledellipse(
            $image,
            (int) ($size * 0.5),
            (int) ($size * 0.99),
            (int) ($size * 0.84),
            (int) ($size * 0.52),
            $shirtColor
        );

        $neck = $this->c($image, (int) ($skin[0] - 10), (int) ($skin[1] - 10), (int) ($skin[2] - 10));
        imagefilledrectangle(
            $image,
            (int) ($size * 0.44),
            (int) ($size * 0.60),
            (int) ($size * 0.56),
            (int) ($size * 0.72),
            $neck
        );

        $headW = (int) ($size * (0.31 + (($variant % 4) * 0.012)));
        $headH = (int) ($size * (0.36 + (($variant % 5) * 0.01)));
        $headX = (int) ($size * 0.5);
        $headY = (int) ($size * 0.40);

        $skinColor = $this->c($image, $skin[0], $skin[1], $skin[2]);
        $skinOutline = $this->c($image, (int) ($skin[0] - 28), (int) ($skin[1] - 24), (int) ($skin[2] - 20));
        imagefilledellipse($image, $headX, $headY, $headW, $headH, $skinColor);
        imageellipse($image, $headX, $headY, $headW, $headH, $skinOutline);

        $hairColor = $this->c($image, $hair[0], $hair[1], $hair[2]);
        $hairHighlight = $this->c($image, min(255, $hair[0] + 24), min(255, $hair[1] + 24), min(255, $hair[2] + 24), 80);

        $hairStyle = $variant % 5;
        if ($gender === 'female') {
            $hairStyle = ($hairStyle + 2) % 5;
        }

        switch ($hairStyle) {
            case 0:
                imagefilledarc($image, $headX, (int) ($headY - $headH * 0.12), (int) ($headW * 1.03), (int) ($headH * 0.95), 180, 360, $hairColor, IMG_ARC_PIE);
                break;
            case 1:
                imagefilledellipse($image, $headX, (int) ($headY - $headH * 0.15), (int) ($headW * 1.10), (int) ($headH * 0.65), $hairColor);
                imagefilledrectangle($image, (int) ($headX - $headW * 0.50), (int) ($headY - $headH * 0.1), (int) ($headX - $headW * 0.25), (int) ($headY + $headH * 0.25), $hairColor);
                imagefilledrectangle($image, (int) ($headX + $headW * 0.25), (int) ($headY - $headH * 0.1), (int) ($headX + $headW * 0.50), (int) ($headY + $headH * 0.25), $hairColor);
                break;
            case 2:
                imagefilledarc($image, $headX, (int) ($headY - $headH * 0.08), (int) ($headW * 1.1), (int) ($headH * 1.2), 175, 365, $hairColor, IMG_ARC_PIE);
                imagefilledellipse($image, (int) ($headX - $headW * 0.38), (int) ($headY + $headH * 0.10), (int) ($headW * 0.26), (int) ($headH * 0.42), $hairColor);
                break;
            case 3:
                imagefilledellipse($image, $headX, (int) ($headY - $headH * 0.2), (int) ($headW * 1.2), (int) ($headH * 0.75), $hairColor);
                imagefilledellipse($image, (int) ($headX + $headW * 0.12), (int) ($headY - $headH * 0.02), (int) ($headW * 0.48), (int) ($headH * 0.35), $hairColor);
                break;
            default:
                imagefilledarc($image, $headX, (int) ($headY - $headH * 0.09), (int) ($headW * 1.04), (int) ($headH * 1.0), 185, 355, $hairColor, IMG_ARC_PIE);
                imagefilledrectangle($image, (int) ($headX - $headW * 0.53), (int) ($headY - $headH * 0.02), (int) ($headX - $headW * 0.33), (int) ($headY + $headH * 0.24), $hairColor);
                imagefilledrectangle($image, (int) ($headX + $headW * 0.33), (int) ($headY - $headH * 0.02), (int) ($headX + $headW * 0.53), (int) ($headY + $headH * 0.24), $hairColor);
                break;
        }
        imagefilledellipse($image, (int) ($headX - $headW * 0.18), (int) ($headY - $headH * 0.25), (int) ($headW * 0.22), (int) ($headH * 0.12), $hairHighlight);

        $eyeY = (int) ($headY - $headH * 0.03);
        $eyeGap = (int) ($headW * (0.18 + (($variant % 3) * 0.015)));
        $eyeW = (int) ($headW * (0.1 + (($variant % 4) * 0.01)));
        $eyeH = (int) ($headH * (0.08 + (($variant % 2) * 0.01)));

        $white = $this->c($image, 250, 252, 255);
        $iris = $this->c($image, $eyes[0], $eyes[1], $eyes[2]);
        $pupil = $this->c($image, 20, 24, 30);

        foreach ([-1, 1] as $side) {
            $x = $headX + ($side * $eyeGap);
            imagefilledellipse($image, $x, $eyeY, $eyeW, $eyeH, $white);
            imagefilledellipse($image, $x, $eyeY, (int) ($eyeW * 0.55), (int) ($eyeH * 0.85), $iris);
            imagefilledellipse($image, $x, $eyeY, (int) ($eyeW * 0.25), (int) ($eyeH * 0.45), $pupil);
        }

        $brow = $this->c($image, (int) ($hair[0] * 0.9), (int) ($hair[1] * 0.9), (int) ($hair[2] * 0.9));
        imagesetthickness($image, 2 + ($variant % 2));
        imageline($image, (int) ($headX - $eyeGap - $eyeW * 0.45), (int) ($eyeY - $eyeH), (int) ($headX - $eyeGap + $eyeW * 0.45), (int) ($eyeY - $eyeH - ($variant % 3)), $brow);
        imageline($image, (int) ($headX + $eyeGap - $eyeW * 0.45), (int) ($eyeY - $eyeH - ($variant % 3)), (int) ($headX + $eyeGap + $eyeW * 0.45), (int) ($eyeY - $eyeH), $brow);
        imagesetthickness($image, 1);

        $nose = $this->c($image, (int) ($skin[0] - 22), (int) ($skin[1] - 18), (int) ($skin[2] - 12));
        $noseY = (int) ($headY + $headH * 0.05);
        imageline($image, $headX, (int) ($noseY - $headH * 0.06), (int) ($headX + (($variant % 2) ? 3 : -3)), $noseY, $nose);
        imagearc($image, $headX, (int) ($noseY + 2), 10, 7, 25, 155, $nose);

        $lipBase = $gender === 'female'
            ? [184, 86, 103]
            : [156, 89, 76];
        $lip = $this->c($image, $lipBase[0] + (($variant % 2) * 10), $lipBase[1], $lipBase[2]);
        $mouthY = (int) ($headY + $headH * 0.20);
        $mouthW = (int) ($headW * (0.22 + (($variant % 3) * 0.02)));
        $mouthH = (int) ($headH * (0.08 + (($variant % 2) * 0.015)));
        imagearc($image, $headX, $mouthY, $mouthW, $mouthH, 12, 168, $lip);

        $hasGlasses = ($variant % 4 === 0);
        if ($hasGlasses) {
            $frame = $this->c($image, 72, 83, 101, 90);
            imageellipse($image, (int) ($headX - $eyeGap), $eyeY, (int) ($eyeW * 1.4), (int) ($eyeH * 1.25), $frame);
            imageellipse($image, (int) ($headX + $eyeGap), $eyeY, (int) ($eyeW * 1.4), (int) ($eyeH * 1.25), $frame);
            imageline($image, (int) ($headX - 5), $eyeY, (int) ($headX + 5), $eyeY, $frame);
        }

        if ($gender === 'male' && ($variant % 3 === 1)) {
            $beard = $this->c($image, (int) ($hair[0] * 0.8), (int) ($hair[1] * 0.8), (int) ($hair[2] * 0.8), 80);
            imagefilledarc($image, $headX, (int) ($headY + $headH * 0.14), (int) ($headW * 0.8), (int) ($headH * 0.6), 20, 160, $beard, IMG_ARC_CHORD);
        }

        $shine = $this->c($image, 255, 255, 255, 42);
        imagefilledellipse($image, (int) ($size * 0.27), (int) ($size * 0.22), (int) ($size * 0.34), (int) ($size * 0.18), $shine);
    }

    private function fillVerticalGradient($image, int $width, int $height, array $top, array $bottom): void
    {
        for ($y = 0; $y < $height; $y++) {
            $ratio = $height > 1 ? $y / ($height - 1) : 0;
            $r = (int) round($top[0] + (($bottom[0] - $top[0]) * $ratio));
            $g = (int) round($top[1] + (($bottom[1] - $top[1]) * $ratio));
            $b = (int) round($top[2] + (($bottom[2] - $top[2]) * $ratio));
            $color = $this->c($image, $r, $g, $b);
            imageline($image, 0, $y, $width, $y, $color);
        }
    }

    private function c($image, int $r, int $g, int $b, int $alpha = 0): int
    {
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));
        $alpha = max(0, min(127, $alpha));

        return imagecolorallocatealpha($image, $r, $g, $b, $alpha);
    }
}
