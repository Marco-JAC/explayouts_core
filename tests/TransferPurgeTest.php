<?php
/**
 * An import that changes what the site shows purges the page caches, also on the command line (exp:layouts import),
 * where no request to the layout editor did it: the pages kept the old layout (X-Exp-Cache: HIT) until the cache was
 * cleared by hand. A layout imported as a draft, a dry run or a refused import purge nothing. No database.
 *
 * @license GPL-2.0-or-later
 */
use PHPUnit\Framework\TestCase;

if ( !class_exists( 'ezpHttpCacheListener', false ) )
{
    class ezpHttpCacheListener
    {
        public static $purged = array();
        public static function purgeTags( array $tags ) { self::$purged[] = $tags; }
    }
}

class TransferPurgeTest extends TestCase
{
    public function testPublishedLayoutsAndRulesChangePages(): void
    {
        $written = array( 'ok' => true, 'written' => array( array( 'kind' => 'layout' ) ), 'affected_rules' => array() );
        $this->assertTrue( expLayoutsCoreTransferImporter::pagesChanged( $written, true ), 'a published layout' );
        $this->assertFalse( expLayoutsCoreTransferImporter::pagesChanged( $written, false ), 'a draft changes no page' );
        $this->assertTrue( expLayoutsCoreTransferImporter::pagesChanged( array( 'affected_rules' => array( 4 ) ) + $written, false ), 'a rule' );
        $this->assertFalse( expLayoutsCoreTransferImporter::pagesChanged( array( 'ok' => false ) + $written, true ), 'a refused import' );
        $this->assertFalse( expLayoutsCoreTransferImporter::pagesChanged( array( 'written' => array() ) + $written, true ), 'nothing written' );
    }

    public function testPurgeAsksThePageCacheForEveryPage(): void
    {
        if ( !property_exists( 'ezpHttpCacheListener', 'purged' ) )
            $this->markTestSkipped( 'the kernel page cache is loaded' );
        ezpHttpCacheListener::$purged = array();
        $this->assertTrue( expLayoutsCoreTransferImporter::purgePageCaches() );
        $this->assertSame( array( array( 'ez-all' ) ), ezpHttpCacheListener::$purged );
    }
}
