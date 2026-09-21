<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Enums\ConstraintType;
use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;
use GdImage;

/**
 * The arrangement, drawn from above, by the server.
 *
 * The renderer has always been able to be given a picture of the plan, and on a customer's
 * first design there has never been one: the browser draws that picture, and on a first
 * design nobody has opened the 3D room yet. So `render_inputs.layout` was null on every
 * first design ever made, and a photorealistic model with no structure to follow does what
 * it is good at — it invents a handsome room. The product owner's own living room came back
 * with the window where their television is.
 *
 * This is the missing picture. No browser, no WebGL: a plan view of the confirmed geometry
 * with the openings marked and every product drawn at its real size where the composer put
 * it. Plain, flat and unmistakably a diagram, because the one thing it must never be is
 * something the renderer might copy as a style.
 *
 * A plan is not a photograph and cannot say what the room looks like from the door. It can
 * say what is against which wall, how wide the window is and what stands in front of it,
 * which is the part the renderer keeps getting wrong.
 */
final class LayoutPlanImage
{
    /** The long side of the drawing. Big enough to read, small enough to send. */
    private const LONG_EDGE = 1024;

    /** White paper, black ink, one grey for the furniture. A diagram, not a mood. */
    private const MARGIN = 48;

    /**
     * Draws the layout and returns PNG bytes, or null when there is nothing to draw.
     *
     * @param  list<RoomConstraint>  $openings
     */
    public function draw(RoomGeometryVersion $geometry, DesignLayout $layout, array $openings): ?string
    {
        $widthMm = (int) $geometry->width_mm;
        $lengthMm = (int) $geometry->length_mm;

        if ($widthMm < 1 || $lengthMm < 1) {
            return null;
        }

        /*
         * The room's own proportions, never stretched.
         *
         * A plan drawn to fit a square is a plan of a different room, and everything measured
         * off it afterwards is wrong by the same factor.
         */
        $scale = (self::LONG_EDGE - self::MARGIN * 2) / max($widthMm, $lengthMm);

        $canvasWidth = (int) round($widthMm * $scale) + self::MARGIN * 2;
        $canvasHeight = (int) round($lengthMm * $scale) + self::MARGIN * 2;

        $image = imagecreatetruecolor($canvasWidth, $canvasHeight);

        if ($image === false) {
            return null;
        }

        $paper = (int) imagecolorallocate($image, 255, 255, 255);
        $ink = (int) imagecolorallocate($image, 20, 20, 20);
        $wall = (int) imagecolorallocate($image, 60, 60, 60);
        $piece = (int) imagecolorallocate($image, 225, 225, 225);
        $glass = (int) imagecolorallocate($image, 90, 150, 210);
        $doorway = (int) imagecolorallocate($image, 200, 120, 40);

        imagefilledrectangle($image, 0, 0, $canvasWidth, $canvasHeight, $paper);

        $x0 = self::MARGIN;
        $z0 = self::MARGIN;
        $x1 = $canvasWidth - self::MARGIN;
        $z1 = $canvasHeight - self::MARGIN;

        // The walls, thick enough to read as walls rather than as a border.
        imagesetthickness($image, 6);
        imagerectangle($image, $x0, $z0, $x1, $z1, $wall);
        imagesetthickness($image, 1);

        $this->label($image, $ink, (int) (($x0 + $x1) / 2) - 20, $z0 - 22, 'KUZEY');
        $this->label($image, $ink, (int) (($x0 + $x1) / 2) - 20, $z1 + 10, 'GUNEY');
        $this->label($image, $ink, 4, (int) (($z0 + $z1) / 2), 'BATI');
        $this->label($image, $ink, $x1 + 6, (int) (($z0 + $z1) / 2), 'DOGU');

        foreach ($openings as $opening) {
            $this->opening($image, $opening, $x0, $z0, $x1, $z1, $scale, $glass, $doorway, $ink);
        }

        foreach ($layout->items()->with(['sku.dimensions', 'product.primaryCategory'])->get() as $item) {
            $dimensions = $item->sku?->dimensions;

            $itemWidth = (int) ($dimensions->width_mm ?? 0);
            $itemDepth = (int) ($dimensions->depth_mm ?? 0);

            if ($itemWidth < 1 || $itemDepth < 1) {
                continue;
            }

            // A piece turned a quarter is as wide as it is deep, which is the whole reason
            // the plan is worth drawing rather than described in words.
            $turned = in_array(((int) $item->rotation_y_deg % 360 + 360) % 360, [90, 270], true);

            $alongX = $turned ? $itemDepth : $itemWidth;
            $alongZ = $turned ? $itemWidth : $itemDepth;

            $left = $x0 + (int) round(((int) $item->position_x_mm - intdiv($alongX, 2)) * $scale);
            $top = $z0 + (int) round(((int) $item->position_z_mm - intdiv($alongZ, 2)) * $scale);
            $right = $left + (int) round($alongX * $scale);
            $bottom = $top + (int) round($alongZ * $scale);

            imagefilledrectangle($image, $left, $top, $right, $bottom, $piece);
            imagerectangle($image, $left, $top, $right, $bottom, $ink);

            $name = (string) ($item->product?->primaryCategory->slug ?? '');

            if ($name !== '') {
                $this->label($image, $ink, $left + 4, (int) (($top + $bottom) / 2) - 7, mb_strtoupper($name, 'UTF-8'));
            }
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes === '' ? null : $bytes;
    }

    /**
     * One door or window, on the wall it belongs to.
     *
     * Drawn over the wall line in its own colour and named, because "there is a gap here" and
     * "this gap is a window 2.4 m wide" are different amounts of help.
     */
    private function opening(
        GdImage $image,
        RoomConstraint $opening,
        int $x0,
        int $z0,
        int $x1,
        int $z1,
        float $scale,
        int $glass,
        int $doorway,
        int $ink,
    ): void {
        $offset = $opening->offset_mm;
        $width = $opening->width_mm;
        $wall = $opening->wall;

        if ($offset === null || $width === null || $wall === null || $width < 1) {
            return;
        }

        $isDoor = in_array($opening->type, [ConstraintType::Door, ConstraintType::BalconyDoor], true);
        $colour = $isDoor ? $doorway : $glass;
        $name = $isDoor ? 'KAPI' : 'PENCERE';

        $from = (int) round($offset * $scale);
        $to = (int) round(($offset + $width) * $scale);

        imagesetthickness($image, 10);

        match ($wall) {
            'north' => imageline($image, $x0 + $from, $z0, $x0 + $to, $z0, $colour),
            'south' => imageline($image, $x0 + $from, $z1, $x0 + $to, $z1, $colour),
            'west' => imageline($image, $x0, $z0 + $from, $x0, $z0 + $to, $colour),
            default => imageline($image, $x1, $z0 + $from, $x1, $z0 + $to, $colour),
        };

        imagesetthickness($image, 1);

        [$labelX, $labelZ] = match ($wall) {
            'north' => [$x0 + $from + 4, $z0 + 8],
            'south' => [$x0 + $from + 4, $z1 - 22],
            'west' => [$x0 + 8, $z0 + $from + 4],
            default => [$x1 - 60, $z0 + $from + 4],
        };

        $this->label($image, $ink, $labelX, $labelZ, $name);
    }

    /** A word on the drawing, in the only font that is always there. */
    private function label(GdImage $image, int $colour, int $x, int $y, string $text): void
    {
        // The built-in font has no Turkish letters; anything it cannot draw would come out as
        // a box, which on a diagram reads as a symbol rather than as a missing glyph.
        $plain = strtr($text, ['Ç' => 'C', 'Ğ' => 'G', 'İ' => 'I', 'Ö' => 'O', 'Ş' => 'S', 'Ü' => 'U', 'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']);

        imagestring($image, 3, $x, $y, $plain, $colour);
    }
}
