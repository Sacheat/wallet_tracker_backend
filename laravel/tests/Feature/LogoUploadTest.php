<?php

namespace Tests\Feature;

use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdatePlatformRequest;
use App\Models\Asset;
use App\Models\Platform;
use App\Services\LogoStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * No DB here (see ExchangeRatesTest), and no GD either — so no
 * UploadedFile::fake()->image(): the PNGs are built byte by byte.
 */
class LogoUploadTest extends TestCase
{
    /** Minimal valid 1x1 PNG, with its IHDR width/height overridable. */
    private function png(int $width = 1, int $height = 1): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        // IHDR data starts at byte 16: width then height, 4 bytes big-endian.
        // getimagesize() doesn't check the chunk CRC, which is all we need.
        return substr_replace($png, pack('NN', $width, $height), 16, 8);
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function passes(UploadedFile $file): bool
    {
        return Validator::make(['logo' => $file], ['logo' => LogoStorage::RULES])->passes();
    }

    public function test_a_small_png_is_accepted(): void
    {
        $this->assertTrue($this->passes($this->upload('logo.png', $this->png())));
    }

    public function test_svg_is_rejected(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->assertFalse($this->passes($this->upload('logo.svg', $svg)));
    }

    public function test_html_disguised_as_png_is_rejected(): void
    {
        $this->assertFalse($this->passes($this->upload('logo.png', '<html><script>alert(1)</script></html>')));
    }

    public function test_oversized_dimensions_are_rejected(): void
    {
        $this->assertFalse($this->passes($this->upload('logo.png', $this->png(4000, 4000))));
    }

    public function test_logo_url_can_no_longer_be_submitted_as_a_url(): void
    {
        $rules = (new StoreAssetRequest)->rules();
        $platformRules = (new UpdatePlatformRequest)->rules();

        $this->assertTrue(Validator::make(['logo_url' => 'https://evil.example/pixel.png'], ['logo_url' => $rules['logo_url']])->fails());
        $this->assertTrue(Validator::make(['logo_url' => 'https://evil.example/pixel.png'], ['logo_url' => $platformRules['logo_url']])->fails());
        // A frontend still sending an empty field isn't broken by it.
        $this->assertTrue(Validator::make(['logo_url' => null], ['logo_url' => $rules['logo_url']])->passes());
    }

    public function test_logo_path_is_server_managed_and_exposed_only_as_logo_url(): void
    {
        $asset = (new Asset)->fill(['name' => 'X', 'logo_path' => '../../.env', 'logo_url' => 'https://evil.example']);
        $this->assertNull($asset->logo_path);
        $this->assertNull($asset->toArray()['logo_url']);

        $platform = new Platform(['name' => 'Y']);
        $platform->logo_path = 'logos/abc.png';
        $json = $platform->toArray();

        $this->assertArrayNotHasKey('logo_path', $json);
        $this->assertStringEndsWith('/storage/logos/abc.png', $json['logo_url']);
    }
}
