<?php

declare(strict_types=1);

namespace Mo7mud\LaravelPaymentGateway\Tests\Unit\Admin;

use InvalidArgumentException;
use Mo7mud\LaravelPaymentGateway\Admin\EnvironmentManager;
use PHPUnit\Framework\TestCase;

final class EnvironmentManagerTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/pg-env-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->path = $this->directory.'/.env';
        file_put_contents($this->path, "APP_KEY=base64:abc\nKASHIER_SECRET_KEY=old\n# comment\nKASHIER_MERCHANT_ID=\"MID 1\" # note\n");
        chmod($this->path, 0640);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $name) {
            $file = $this->directory.'/'.$name;
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_values_with_backreferences_and_variables_are_written_literally(): void
    {
        $manager = new EnvironmentManager($this->path);
        $secret = 'a$1b$0c${APP_KEY}\\d#e f';

        $manager->set(['KASHIER_SECRET_KEY' => $secret, 'KASHIER_PAYMENT_API_KEY' => 'new-key']);

        $contents = (string) file_get_contents($this->path);
        self::assertStringContainsString("KASHIER_SECRET_KEY='{$secret}'", $contents);
        self::assertStringContainsString("KASHIER_PAYMENT_API_KEY='new-key'", $contents);
        self::assertStringContainsString('APP_KEY=base64:abc', $contents);
        self::assertSame($secret, $manager->get('KASHIER_SECRET_KEY'));
        self::assertSame('MID 1', $manager->get('KASHIER_MERCHANT_ID'));
    }

    public function test_file_permissions_are_preserved(): void
    {
        (new EnvironmentManager($this->path))->set(['KASHIER_SECRET_KEY' => 'x']);

        clearstatcache();
        self::assertSame(0640, fileperms($this->path) & 0777);
    }

    public function test_values_that_cannot_be_written_safely_are_rejected(): void
    {
        $manager = new EnvironmentManager($this->path);

        foreach (["line\nbreak", "quote'd", "nul\0byte"] as $value) {
            try {
                $manager->set(['KASHIER_SECRET_KEY' => $value]);
                self::fail('Expected InvalidArgumentException for '.json_encode($value));
            } catch (InvalidArgumentException) {
                self::assertSame('old', $manager->get('KASHIER_SECRET_KEY'));
            }
        }
    }

    public function test_invalid_variable_names_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EnvironmentManager($this->path))->set(["APP_KEY=x\nEVIL" => 'value']);
    }
}
