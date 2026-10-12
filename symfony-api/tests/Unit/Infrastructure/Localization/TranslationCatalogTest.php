<?php

namespace App\Tests\Unit\Infrastructure\Localization;

use App\Infrastructure\Localization\TranslationCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TranslationCatalogTest extends TestCase
{
    /** @var list<string> */
    private array $fixturePaths = [];

    protected function tearDown(): void
    {
        foreach ($this->fixturePaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function fixture(string $yaml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tr-');
        file_put_contents($path, $yaml);
        $this->fixturePaths[] = $path;

        return $path;
    }

    #[Test]
    public function partial_leaves_fall_back_to_english_then_the_first_scalar(): void
    {
        $catalog = new TranslationCatalog(new ArrayAdapter(), $this->fixture(<<<'YAML'
section:
  partial: { en: "English", pl: "Polski" }
  no_en: { ru: "Русский", pl: "Polski" }
YAML));

        $this->assertSame('English', $catalog->forLocale('ru')['section.partial']);
        $this->assertSame('Polski', $catalog->forLocale('pl')['section.partial']);
        $this->assertSame('Русский', $catalog->forLocale('en')['section.no_en']);
        $this->assertSame('Polski', $catalog->forLocale('pl')['section.no_en']);
        $this->assertSame('English', $catalog->forLocale('de')['section.partial']);
    }

    #[Test]
    public function only_unambiguous_basenames_get_bare_aliases(): void
    {
        $catalog = new TranslationCatalog(new ArrayAdapter(), $this->fixture(<<<'YAML'
a:
  name: { en: "A name" }
  leaf: { en: "Unique" }
b:
  name: { en: "B name" }
  other: "scalar"
YAML));

        $en = $catalog->forLocale('en');
        $this->assertSame('A name', $en['a.name']);
        $this->assertSame('B name', $en['b.name']);
        $this->assertArrayNotHasKey('name', $en);
        $this->assertSame('Unique', $en['leaf']);
        $this->assertSame('scalar', $en['other']);
    }

    #[Test]
    public function catalogs_are_memoized_per_locale_and_shared_through_the_cache_pool(): void
    {
        $pool = new ArrayAdapter();
        $path = $this->fixture("key:\n  value: { en: \"Alpha\" }\n");
        $mtime = filemtime($path);
        $version = md5($mtime.':'.filesize($path));

        $first = new TranslationCatalog($pool, $path);
        $en = $first->forLocale('en');
        $this->assertSame('Alpha', $en['key.value']);
        $this->assertSame($en, $first->forLocale('en'));
        $this->assertSame('Alpha', $first->forLocale('pl')['key.value']);
        $this->assertTrue($pool->hasItem('tr.en.'.$version));

        file_put_contents($path, "key:\n  value: { en: \"Omega\" }\n");
        touch($path, $mtime);
        clearstatcache();
        $this->assertSame($version, md5(filemtime($path).':'.filesize($path)));
        $this->assertStringContainsString('Omega', (string) file_get_contents($path));

        $second = new TranslationCatalog($pool, $path);
        $this->assertSame('Alpha', $second->forLocale('en')['key.value']);
    }

    #[Test]
    public function legacy_aliases_and_replacements_behave_like_the_storefront(): void
    {
        $catalog = new TranslationCatalog(new ArrayAdapter(), $this->fixture(<<<'YAML'
checkout:
  title: { en: "Checkout" }
account:
  signed_in_as: { en: "Signed in as :name" }
YAML));

        $this->assertSame('Checkout', $catalog->forLocale('en')['checkout']);
        $this->assertSame('Signed in as Mira', $catalog->get('account.signed_in_as', ['name' => 'Mira']));
        $this->assertSame('Signed in as :name', $catalog->get('account.signed_in_as'));
        $this->assertSame('missing.key', $catalog->get('missing.key'));
    }

    #[Test]
    public function a_missing_yaml_file_yields_a_none_version_and_an_empty_catalog(): void
    {
        $catalog = new TranslationCatalog(new ArrayAdapter(), sys_get_temp_dir().'/tr-absent.yaml');

        $this->assertSame('none', $catalog->version());
        $this->assertSame([], $catalog->forLocale('en'));
        $this->assertNull($catalog->find('any.key'));
    }
}
