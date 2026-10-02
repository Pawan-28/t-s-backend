<?php

namespace Tests\Feature;

use App\Services\Media\ImageProcessor;
use App\Services\Media\ImageValidationException;
use Tests\Concerns\MediaTestHelpers;
use Tests\TestCase;

class ImageProcessorTest extends TestCase
{
    use MediaTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureBunny();
    }

    private function proc(): ImageProcessor
    {
        return app(ImageProcessor::class);
    }

    private function dims(string $bytes): array
    {
        $i = getimagesizefromstring($bytes);

        return [$i[0], $i[1], $i['mime']];
    }

    public function test_small_image_is_kept_at_its_size_and_facts_are_computed(): void
    {
        $src = $this->imageBytes(200, 100);
        $p = $this->proc()->process($src);

        $this->assertSame([200, 100], [$p->width, $p->height]);
        $this->assertSame('image/jpeg', $p->contentType);
        $this->assertSame('jpg', $p->extension);
        $this->assertSame(hash('sha256', $p->bytes), $p->checksum);
        $this->assertSame(strlen($p->bytes), $p->size());
        $this->assertSame([200, 100, 'image/jpeg'], $this->dims($p->bytes));
    }

    public function test_landscape_over_limit_is_resized_down_keeping_aspect_ratio(): void
    {
        $p = $this->proc()->process($this->imageBytes(4000, 3000));

        $this->assertSame([2000, 1500], [$p->width, $p->height]);
        $this->assertSame([2000, 1500, 'image/jpeg'], $this->dims($p->bytes));
        $this->assertEqualsWithDelta(4000 / 3000, $p->width / $p->height, 0.001);
    }

    public function test_portrait_over_limit_is_resized_down(): void
    {
        $p = $this->proc()->process($this->imageBytes(3000, 5000, 'png'));

        $this->assertSame([1200, 2000], [$p->width, $p->height]);
        $this->assertLessThanOrEqual(2000, max($p->width, $p->height));
    }

    public function test_max_dimension_follows_config_and_never_upscales(): void
    {
        config(['portal.images.max_dimension_px' => 500]);
        $p = $this->proc()->process($this->imageBytes(1000, 400));
        $this->assertSame([500, 200], [$p->width, $p->height]);

        $small = $this->proc()->process($this->imageBytes(100, 60));
        $this->assertSame([100, 60], [$small->width, $small->height]);
    }

    public function test_exif_orientation_6_is_applied_so_landscape_becomes_portrait(): void
    {
        $p = $this->proc()->process($this->imageBytes(200, 100, 'jpeg', false, 6));

        $this->assertSame([100, 200], [$p->width, $p->height]);
        $img = imagecreatefromstring($p->bytes);
        // source top-left red marker moves to the TOP-RIGHT after a 90deg clockwise turn
        $this->assertGreaterThan(200, ($this->rgb($img, 90, 5)[0]));
        $this->assertGreaterThan(200, ($this->rgb($img, 5, 5)[2]));
    }

    public function test_exif_orientation_3_and_8_and_5(): void
    {
        $r3 = $this->proc()->process($this->imageBytes(200, 100, 'jpeg', false, 3));
        $this->assertSame([200, 100], [$r3->width, $r3->height]);
        $img = imagecreatefromstring($r3->bytes);
        $this->assertGreaterThan(200, $this->rgb($img, 195, 95)[0], 'marker rotated to bottom-right');

        $r8 = $this->proc()->process($this->imageBytes(200, 100, 'jpeg', false, 8));
        $this->assertSame([100, 200], [$r8->width, $r8->height]);
        $img8 = imagecreatefromstring($r8->bytes);
        $this->assertGreaterThan(200, $this->rgb($img8, 5, 195)[0], 'marker rotated to bottom-left');

        $r5 = $this->proc()->process($this->imageBytes(200, 100, 'jpeg', false, 5));
        $this->assertSame([100, 200], [$r5->width, $r5->height]);
        $img5 = imagecreatefromstring($r5->bytes);
        $this->assertGreaterThan(200, $this->rgb($img5, 5, 5)[0], 'transpose keeps marker top-left');
    }

    public function test_output_has_no_exif_metadata(): void
    {
        $p = $this->proc()->process($this->imageBytes(200, 100, 'jpeg', false, 6));
        $this->assertStringNotContainsString('Exif', $p->bytes);
    }

    public function test_large_valid_image_bigger_than_limit_is_accepted_not_rejected(): void
    {
        // 6000x4000 solid colour compresses far below the byte limit
        $p = $this->proc()->process($this->imageBytes(6000, 4000, 'jpeg'));
        $this->assertSame([2000, 1333], [$p->width, $p->height]);
    }

    public function test_png_with_alpha_stays_png_and_keeps_transparency(): void
    {
        $p = $this->proc()->process($this->imageBytes(300, 300, 'png', true));
        $this->assertSame('image/png', $p->contentType);
        $this->assertSame('png', $p->extension);
        $img = imagecreatefromstring($p->bytes);
        $alpha = (imagecolorat($img, 290, 150) >> 24) & 0x7F;
        $this->assertGreaterThan(60, $alpha, 'transparent half must stay transparent');
    }

    public function test_alpha_png_resized_keeps_alpha(): void
    {
        $p = $this->proc()->process($this->imageBytes(3000, 3000, 'png', true));
        $this->assertSame([2000, 2000], [$p->width, $p->height]);
        $this->assertSame('image/png', $p->contentType);
    }

    public function test_opaque_png_webp_and_gif_become_jpeg(): void
    {
        foreach (['png', 'webp', 'gif'] as $kind) {
            $p = $this->proc()->process($this->imageBytes(120, 80, $kind));
            $this->assertSame('image/jpeg', $p->contentType, $kind);
            $this->assertSame([120, 80, 'image/jpeg'], $this->dims($p->bytes));
        }
    }

    public function test_webp_with_alpha_stays_transparent_png(): void
    {
        $p = $this->proc()->process($this->imageBytes(120, 80, 'webp', true));
        $this->assertSame('image/png', $p->contentType);
    }

    public function test_non_image_bytes_are_rejected_regardless_of_name(): void
    {
        foreach (['plain text file', "<?php echo 'x';", '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', str_repeat("\x01\xfe\x9c", 170)] as $junk) {
            try {
                $this->proc()->process($junk);
                $this->fail('should have been rejected');
            } catch (ImageValidationException $e) {
                $this->assertSame(ImageProcessor::MSG_NOT_IMAGE, $e->getMessage());
            }
        }
    }

    public function test_truncated_or_corrupt_image_is_rejected(): void
    {
        $good = $this->imageBytes(300, 300, 'jpeg');
        $this->expectException(ImageValidationException::class);
        $this->proc()->process(substr($good, 0, 200));
    }

    public function test_valid_header_with_garbage_body_is_rejected(): void
    {
        $png = $this->imageBytes(300, 300, 'png');
        $bad = substr($png, 0, 60).str_repeat("\x00", 400); // header ok, data destroyed
        $this->expectException(ImageValidationException::class);
        $this->proc()->process($bad);
    }

    public function test_unsupported_but_real_image_format_is_rejected(): void
    {
        $img = imagecreatetruecolor(64, 64);
        ob_start();
        imagebmp($img);
        $bmp = (string) ob_get_clean();
        try {
            $this->proc()->process($bmp);
            $this->fail('bmp accepted');
        } catch (ImageValidationException $e) {
            $this->assertStringContainsString("Unsupported image format 'BMP'", $e->getMessage());
        }
    }

    public function test_bytes_over_the_configured_limit_are_rejected(): void
    {
        config(['portal.images.max_upload_mb' => 1]);
        $big = $this->imageBytes(300, 300).str_repeat('A', 1024 * 1024);
        try {
            $this->proc()->process($big);
            $this->fail('oversize accepted');
        } catch (ImageValidationException $e) {
            $this->assertSame('Image exceeds the maximum upload size of 1MB.', $e->getMessage());
        }
    }

    public function test_too_small_and_empty_are_rejected(): void
    {
        try {
            $this->proc()->process($this->imageBytes(20, 20));
            $this->fail();
        } catch (ImageValidationException $e) {
            $this->assertSame('Image is too small (20x20px); minimum is 32x32px.', $e->getMessage());
        }
        $this->expectException(ImageValidationException::class);
        $this->proc()->process('');
    }

    public function test_processing_is_deterministic_for_checksum(): void
    {
        $a = $this->proc()->process($this->imageBytes(200, 100));
        $b = $this->proc()->process($this->imageBytes(200, 100));
        $this->assertSame($a->checksum, $b->checksum);
    }

    private function rgb(\GdImage $img, int $x, int $y): array
    {
        $c = imagecolorat($img, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }
}
