<?php

namespace Tests\Unit;

use App\Support\IndustryTaxonomy;
use PHPUnit\Framework\TestCase;

class IndustryTaxonomyTest extends TestCase
{
    public function test_labels_for_family_keys_includes_synonyms_and_unknown_keys(): void
    {
        $labels = IndustryTaxonomy::labelsForFamilyKeys(['pflegefachkraft', 'custom_path']);

        $this->assertContains('pflegefachkraft', $labels);
        $this->assertContains('krankenpfleger', $labels);
        $this->assertContains('custom_path', $labels);
        $this->assertSame($labels, array_values(array_unique($labels)));
    }

    public function test_family_key_unknown_returns_normalized_label(): void
    {
        $this->assertSame('it', IndustryTaxonomy::familyKey('it'));
        $this->assertNull(IndustryTaxonomy::familyKey(null));
        $this->assertNull(IndustryTaxonomy::familyKey(''));
    }
}
