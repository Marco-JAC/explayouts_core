<?php
/**
 * The layouts transfer file: manifest, checksums, version and the schema of the documents. No database.
 *
 * @license GPL-2.0-or-later
 */
use PHPUnit\Framework\TestCase;

class TransferFormatTest extends TestCase
{
    protected $dir;

    protected function setUp(): void
    {
        $this->dir = __DIR__ . '/var-' . getmypid() . '-' . mt_rand();
        mkdir( $this->dir, 0700, true );
    }

    protected function tearDown(): void
    {
        foreach ( glob( $this->dir . '/*' ) ?: array() as $file )
            unlink( $file );
        rmdir( $this->dir );
    }

    public static function documents(): array
    {
        return array(
            'layouts.json' => array( 'layouts' => array(
                array( 'id' => 2, 'identifier' => 'header_footer', 'name' => 'Header / Footer', 'layout_type' => 'layout_1', 'shared' => true,
                       'status' => 'published', 'dependency' => true, 'created' => 1, 'modified' => 2,
                       'zones' => array( array( 'identifier' => 'header', 'position' => 0, 'link' => null, 'blocks' => array(
                           array( 'definition_identifier' => 'title', 'name' => 'Site title', 'view_type' => 'title', 'item_view_type' => '',
                                  'position' => 0, 'placeholder' => '', 'parameters' => array( 'title' => 'Hello' ), 'parameter_refs' => array(),
                                  'collections' => array(), 'children' => array() ) ) ) ) ),
                array( 'id' => 29, 'identifier' => 'home', 'name' => 'Home', 'layout_type' => 'layout_2', 'shared' => false,
                       'status' => 'published', 'dependency' => false, 'created' => 1, 'modified' => 2,
                       'zones' => array(
                           array( 'identifier' => 'header', 'position' => 0, 'link' => array( 'layout' => 'header_footer', 'zone' => 'header' ), 'blocks' => array() ),
                           array( 'identifier' => 'main', 'position' => 1, 'link' => null, 'blocks' => array(
                               array( 'definition_identifier' => 'list', 'name' => 'Latest', 'view_type' => 'list', 'item_view_type' => 'standard',
                                      'position' => 0, 'placeholder' => '', 'parameters' => array( 'parent_node_id' => '89' ),
                                      'parameter_refs' => array( 'parent_node_id' => 'node:89' ),
                                      'collections' => array( array( 'collection_type' => 'manual', 'offset' => 0, 'limit' => 4,
                                          'query' => array( 'query_type' => 'content_by_topic', 'parameters' => '{"parent_location_id":89,"topic_content_id":5}',
                                                            'refs' => array( 'parent_location_id' => 'node:89', 'topic_content_id' => 'object:5' ) ),
                                          'items' => array( array( 'position' => 0, 'value_type' => 'ez_location', 'item_type' => 'manual', 'value_id' => 92, 'ref' => 'node:92' ) ) ) ),
                                      'children' => array() ) ) ) ) ),
            ) ),
            'rules.json' => array( 'rules' => array(
                array( 'id' => 16, 'layout' => 'home', 'priority' => 210, 'enabled' => true,
                       'targets' => array( array( 'type' => 'node', 'value' => '92', 'ref' => 'node:92' ), array( 'type' => 'path_info_prefix', 'value' => '/x', 'ref' => null ) ),
                       'conditions' => array( array( 'type' => 'siteaccess', 'value' => '["site"]' ) ) ),
            ) ),
            'references.json' => array(
                'node' => array( '89' => array( 'remote_id' => 'r89', 'path' => 'media' ), '92' => array( 'remote_id' => 'r92', 'path' => 'media/x' ) ),
                'object' => array( '5' => array( 'remote_id' => 'o5' ) ),
            ),
        );
    }

    public function testAnArchiveWrittenIsReadBackWithItsManifest()
    {
        $files = expLayoutsCoreTransferFormat::files( self::documents(), array( 'source' => array( 'site_url' => 'example.test' ) ) );
        $this->assertSame( array( 'layouts.json', 'rules.json', 'references.json', 'manifest.json' ), array_keys( $files ) );

        $path = $this->dir . '/t.zip';
        $this->assertTrue( expLayoutsCoreTransferFormat::writeArchive( $path, $files ) );
        $read = expLayoutsCoreTransferFormat::readArchive( $path );
        $this->assertTrue( $read['ok'], implode( ' ', $read['errors'] ) );
        $this->assertSame( 'exponential-layouts-transfer', $read['manifest']['format'] );
        $this->assertSame( 1, $read['manifest']['format_version'] );
        $this->assertSame( 'example.test', $read['manifest']['source']['site_url'] );
        $this->assertSame( 2, $read['manifest']['counts']['layouts'] );
        $this->assertSame( 1, $read['manifest']['counts']['shared_layouts'] );
        $this->assertSame( 1, $read['manifest']['counts']['linked_zones'] );
        $this->assertSame( 2, $read['manifest']['counts']['blocks'] );
        $this->assertSame( 1, $read['manifest']['counts']['items'] );
        $this->assertSame( 1, $read['manifest']['counts']['queries'] );
        $this->assertSame( 2, $read['manifest']['counts']['targets'] );
        foreach ( array( 'layouts.json', 'rules.json', 'references.json' ) as $name )
            $this->assertSame( hash( 'sha256', $files[$name] ), $read['manifest']['files'][$name]['sha256'] );
        $this->assertSame( 'home', $read['documents']['layouts.json']['layouts'][1]['identifier'] );
    }

    public function testATamperedDocumentFailsItsChecksum()
    {
        $files = expLayoutsCoreTransferFormat::files( self::documents() );
        $files['rules.json'] = str_replace( '210', '999', $files['rules.json'] );
        $read = expLayoutsCoreTransferFormat::readFiles( $files );
        $this->assertFalse( $read['ok'] );
        $this->assertSame( array( 'The checksum of rules.json does not match the manifest.' ), $read['errors'] );
    }

    public function testAnotherFormatOrANewerVersionIsRefusedBeforeAnythingElse()
    {
        $files = expLayoutsCoreTransferFormat::files( self::documents() );
        $manifest = json_decode( $files['manifest.json'], true );

        $newer = $manifest;
        $newer['format_version'] = expLayoutsCoreTransferFormat::VERSION + 1;
        $read = expLayoutsCoreTransferFormat::readFiles( array( 'manifest.json' => json_encode( $newer ) ) + $files );
        $this->assertFalse( $read['ok'] );
        $this->assertStringContainsString( 'is not supported', $read['errors'][0] );

        $other = $manifest;
        $other['format'] = 'something-else';
        $read = expLayoutsCoreTransferFormat::readFiles( array( 'manifest.json' => json_encode( $other ) ) + $files );
        $this->assertFalse( $read['ok'] );
        $this->assertStringContainsString( 'not a layouts transfer file', $read['errors'][0] );

        $read = expLayoutsCoreTransferFormat::readFiles( array( 'layouts.json' => $files['layouts.json'] ) );
        $this->assertSame( array( 'The archive has no manifest.json.' ), $read['errors'] );
    }

    public function testAFileThatIsNoZipIsRefused()
    {
        file_put_contents( $this->dir . '/x.zip', '{"version":1}' );
        $read = expLayoutsCoreTransferFormat::readArchive( $this->dir . '/x.zip' );
        $this->assertFalse( $read['ok'] );
        $this->assertSame( array( 'The file is not a ZIP archive.' ), $read['errors'] );
    }

    public function testUnknownEntriesAreIgnoredWithAWarning()
    {
        $files = expLayoutsCoreTransferFormat::files( self::documents() );
        $files['../evil.php'] = '<?php';
        $path = $this->dir . '/t.zip';
        expLayoutsCoreTransferFormat::writeArchive( $path, $files );
        $read = expLayoutsCoreTransferFormat::readArchive( $path );
        $this->assertTrue( $read['ok'] );
        $this->assertSame( array( 'An unknown entry was ignored: ../evil.php' ), $read['warnings'] );
    }

    public function testTheSchemaNamesWhatIsWrong()
    {
        $documents = self::documents();
        $documents['layouts.json']['layouts'][1]['identifier'] = 'bad identifier!';
        $documents['layouts.json']['layouts'][0]['shared'] = 'yes';
        $documents['layouts.json']['layouts'][0]['zones'][0]['blocks'][0]['collections'] = array( array( 'items' => array( array( 'value_type' => 'ez_location', 'value_id' => '7' ) ) ) );
        $errors = expLayoutsCoreTransferFormat::validateDocuments( $documents );
        $this->assertContains( 'layout #2 has no valid identifier.', $errors );
        $this->assertContains( 'layout "header_footer": "shared" must be true or false.', $errors );
        $this->assertContains( 'layout "header_footer", zone "header", block "Site title": every collection item needs a value_type and a numeric value_id.', $errors );
        $this->assertContains( 'rule #1 maps to "home", which the archive does not carry as a published layout.', $errors );
    }

    public function testALinkToALayoutTheArchiveDoesNotCarryIsAnError()
    {
        $documents = self::documents();
        array_shift( $documents['layouts.json']['layouts'] );
        $errors = expLayoutsCoreTransferFormat::validateDocuments( $documents );
        $this->assertSame( array( 'layout "home" links to "header_footer", which the archive does not carry.' ), $errors );
    }

    public function testALayoutTwiceWithOneStatusOrAZoneTwiceIsAnError()
    {
        $documents = self::documents();
        $documents['layouts.json']['layouts'][] = $documents['layouts.json']['layouts'][1];
        $documents['layouts.json']['layouts'][1]['zones'][] = $documents['layouts.json']['layouts'][1]['zones'][1];
        $errors = expLayoutsCoreTransferFormat::validateDocuments( $documents );
        $this->assertContains( 'layout "home" appears twice with status published.', $errors );
        $this->assertContains( 'layout "home" has the zone "main" twice.', $errors );
    }

    public function testAnEmptyDefinitionIdentifierIsAccepted()
    {
        $documents = self::documents();
        $documents['layouts.json']['layouts'][0]['zones'][0]['blocks'][0]['definition_identifier'] = '';
        $this->assertSame( array(), expLayoutsCoreTransferFormat::validateDocuments( $documents ) );
    }

    public function testReferencesAreParsed()
    {
        $this->assertSame( array( 'node', 89 ), expLayoutsCoreTransferFormat::parseReference( 'node:89' ) );
        $this->assertSame( array( 'object', 5 ), expLayoutsCoreTransferFormat::parseReference( 'object:5' ) );
        $this->assertFalse( expLayoutsCoreTransferFormat::parseReference( 'tag:5' ) );
        $this->assertFalse( expLayoutsCoreTransferFormat::parseReference( null ) );
    }
}
