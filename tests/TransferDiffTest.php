<?php
/**
 * The diff of a layouts transfer (new, identical, changed, conflicting), the actions the strategies give and the
 * mapping of node and object ids by remote id. No database.
 *
 * @license GPL-2.0-or-later
 */
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TransferFormatTest.php';

class TransferDiffTest extends TestCase
{
    /**
     * The same layouts as they look on another installation: other row ids and other node and object ids, the
     * same remote ids.
     */
    protected static function elsewhere(): array
    {
        $documents = TransferFormatTest::documents();
        $json = json_encode( $documents );
        $json = str_replace( array( 'node:89', 'node:92', 'object:5', '"value":"92"', '"value_id":92', '"parent_node_id":"89"', '"id":29', '"id":2,' ),
                             array( 'node:1089', 'node:1092', 'object:1005', '"value":"1092"', '"value_id":1092', '"parent_node_id":"1089"', '"id":129', '"id":102,' ), $json );
        $json = str_replace( '{\"parent_location_id\":89,\"topic_content_id\":5}', '{\"parent_location_id\":1089,\"topic_content_id\":1005}', $json );
        $documents = json_decode( $json, true );
        $documents['references.json'] = array(
            'node' => array( '1089' => array( 'remote_id' => 'r89', 'path' => 'media' ), '1092' => array( 'remote_id' => 'r92', 'path' => 'media/x' ) ),
            'object' => array( '1005' => array( 'remote_id' => 'o5' ) ),
        );
        return $documents;
    }

    protected static function states( array $plan ): array
    {
        $states = array();
        foreach ( array( 'layouts', 'rules' ) as $list )
            foreach ( $plan[$list] as $item )
                $states[$item['key']] = $item['state'];
        return $states;
    }

    public function testTheSameContentWithOtherIdsIsIdentical()
    {
        $plan = expLayoutsCoreTransferDiff::plan( TransferFormatTest::documents(), self::elsewhere() );
        $this->assertSame( array( 'new' => 0, 'identical' => 3, 'changed' => 0, 'conflicting' => 0 ), $plan['totals'] );
        $this->assertSame( 129, $plan['layouts'][1]['target_id'] );
    }

    public function testAnEmptyInstallationGetsEverythingNew()
    {
        $target = array( 'layouts.json' => array( 'layouts' => array() ), 'rules.json' => array( 'rules' => array() ), 'references.json' => array( 'node' => array(), 'object' => array() ) );
        $plan = expLayoutsCoreTransferDiff::plan( TransferFormatTest::documents(), $target );
        $this->assertSame( 3, $plan['totals']['new'] );
        $this->assertTrue( $plan['layouts'][0]['dependency'] );
        $this->assertFalse( $plan['layouts'][1]['dependency'] );
    }

    public function testAnotherCollectionItemIsAChangeWithItsPath()
    {
        $target = self::elsewhere();
        $target['references.json']['node']['1092']['remote_id'] = 'something-else';
        $plan = expLayoutsCoreTransferDiff::plan( TransferFormatTest::documents(), $target );
        $states = self::states( $plan );
        $this->assertSame( 'changed', $states['layout:home:published'] );
        $this->assertSame( array( 'zones.main.blocks.0.collections.0.items.0.value' ), $plan['layouts'][1]['differences'] );
        // The rule's node target is the same node: its key differs, so it is new.
        $this->assertSame( 'new', $plan['rules'][0]['state'] );
    }

    public function testATypeChangeAnOpenDraftOrAnotherSharedFlagIsAConflict()
    {
        $target = self::elsewhere();
        $target['layouts.json']['layouts'][1]['layout_type'] = 'layout_9';
        $plan = expLayoutsCoreTransferDiff::plan( TransferFormatTest::documents(), $target );
        $this->assertSame( 'conflicting', self::states( $plan )['layout:home:published'] );
        $this->assertSame( array( 'The layout type differs.' ), $plan['layouts'][1]['reasons'] );

        $target = self::elsewhere();
        $draft = $target['layouts.json']['layouts'][1];
        $draft['status'] = 'draft';
        $draft['id'] = 130;
        $target['layouts.json']['layouts'][] = $draft;
        $target['layouts.json']['layouts'][1]['name'] = 'Home (renamed here)';
        $plan = expLayoutsCoreTransferDiff::plan( TransferFormatTest::documents(), $target );
        $this->assertSame( 'conflicting', self::states( $plan )['layout:home:published'] );
        $this->assertSame( array( 'A draft of this layout is open on this installation.' ), $plan['layouts'][1]['reasons'] );

        // Not published there, but drafted: the draft is what an overwrite works on.
        $target = self::elsewhere();
        $target['layouts.json']['layouts'][1]['status'] = 'draft';
        $plan = expLayoutsCoreTransferDiff::plan( TransferFormatTest::documents(), $target );
        $this->assertSame( 'conflicting', self::states( $plan )['layout:home:published'] );
        $this->assertSame( 129, $plan['layouts'][1]['target_id'] );
    }

    public function testTheSameTargetsMappedToAnotherLayoutIsAConflictingRule()
    {
        $target = self::elsewhere();
        $target['rules.json']['rules'][0]['layout'] = 'header_footer';
        $plan = expLayoutsCoreTransferDiff::plan( TransferFormatTest::documents(), $target );
        $this->assertSame( 'conflicting', $plan['rules'][0]['state'] );
        $this->assertSame( array( 'The same targets map to the layout "header_footer" on this installation.' ), $plan['rules'][0]['reasons'] );

        $target = self::elsewhere();
        $target['rules.json']['rules'][0]['priority'] = 5;
        $plan = expLayoutsCoreTransferDiff::plan( TransferFormatTest::documents(), $target );
        $this->assertSame( 'changed', $plan['rules'][0]['state'] );
        $this->assertSame( array( 'priority' ), $plan['rules'][0]['differences'] );
    }

    public function testOnlyTakesTheLinkedLayoutsAlongAndLeavesTheRestOut()
    {
        $documents = TransferFormatTest::documents();
        $this->assertSame( array( 'home' => 'selected', 'header_footer' => 'dependency' ), expLayoutsCoreTransferDiff::selectedIdentifiers( $documents, array( 'home' ) ) );
        $this->assertSame( array( 'header_footer' => 'selected' ), expLayoutsCoreTransferDiff::selectedIdentifiers( $documents, array( 'header_footer' ) ) );

        $plan = expLayoutsCoreTransferDiff::plan( $documents, self::elsewhere(), array( 'header_footer' ) );
        $this->assertCount( 1, $plan['layouts'] );
        $this->assertSame( array(), $plan['rules'] );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'actions' )]
    public function testTheStrategyGivesTheAction( $state, $kind, $dependency, $strategy, array $perItem, $expected )
    {
        $item = array( 'key' => 'k', 'kind' => $kind, 'state' => $state, 'dependency' => $dependency );
        $this->assertSame( $expected, expLayoutsCoreTransferDiff::action( $item, $strategy, $perItem ) );
    }

    public static function actions(): array
    {
        return array(
            'new is created' => array( 'new', 'layout', false, 'skip', array(), 'create' ),
            'new skipped by its item' => array( 'new', 'layout', false, 'copy', array( 'k' => 'skip' ), 'skip' ),
            'identical stays' => array( 'identical', 'layout', false, 'overwrite', array(), 'none' ),
            'identical copied' => array( 'identical', 'layout', false, 'copy', array(), 'copy' ),
            'changed skipped' => array( 'changed', 'layout', false, 'skip', array(), 'skip' ),
            'changed overwritten' => array( 'changed', 'layout', false, 'overwrite', array(), 'overwrite' ),
            'conflict copied by its item' => array( 'conflicting', 'layout', false, 'skip', array( 'k' => 'copy' ), 'copy' ),
            'dependency kept' => array( 'changed', 'layout', true, 'overwrite', array(), 'skip' ),
            'dependency identical not copied' => array( 'identical', 'layout', true, 'copy', array(), 'none' ),
            'dependency overwritten by its item' => array( 'changed', 'layout', true, 'skip', array( 'k' => 'overwrite' ), 'overwrite' ),
            'dependency new is created' => array( 'new', 'layout', true, 'skip', array(), 'create' ),
            'rule not copied with the layouts' => array( 'identical', 'rule', false, 'copy', array(), 'none' ),
            'rule copied by its item' => array( 'conflicting', 'rule', false, 'skip', array( 'k' => 'copy' ), 'copy' ),
            'unknown per-item strategy ignored' => array( 'changed', 'layout', false, 'overwrite', array( 'k' => 'drop table' ), 'overwrite' ),
        );
    }

    public function testIdsAreMappedByRemoteId()
    {
        $references = new expLayoutsCoreTransferReferences( array(
            'node_by_remote' => function ( $remoteId ) { return $remoteId === 'r89' ? 1089 : null; },
            'object_by_remote' => function ( $remoteId ) { return $remoteId === 'o5' ? 1005 : null; },
            'node_remote' => function ( $id ) { return null; },
            'object_remote' => function ( $id ) { return null; },
            'node_path' => function ( $id ) { return null; },
        ) );
        $table = TransferFormatTest::documents()['references.json'];
        $this->assertSame( 1089, $references->map( 'node', 89, $table ) );
        $this->assertNull( $references->map( 'node', 92, $table ) );
        $this->assertSame( 1005, $references->map( 'object', 5, $table ) );
        $this->assertNull( $references->map( 'object', 6, $table ) );

        $importer = new expLayoutsCoreTransferImporter( $references );
        $mapping = $importer->mapReferences( TransferFormatTest::documents() );
        $this->assertSame( array( 'node:89' => 1089, 'node:92' => null, 'object:5' => 1005 ), $mapping['mapped'] );
        $this->assertSame( array( array( 'kind' => 'node', 'id' => 92, 'remote_id' => 'r92', 'path' => 'media/x' ) ), $mapping['unmapped'] );
    }

    public function testOnlyTheResolverCacheEntriesOfTheAffectedLayoutsAreRemoved()
    {
        $dir = __DIR__ . '/var-cache-' . getmypid() . '-' . mt_rand();
        mkdir( $dir, 0700, true );
        $entries = array(
            'a' => array( 'layout_id' => 29, 'rule_id' => 16 ),
            'b' => array( 'layout_id' => 30, 'rule_id' => 17 ),
            'c' => array( 'layout_id' => 0, 'rule_id' => 0 ),
            'd' => array( 'layout_id' => 31, 'rule_id' => 18 ),
        );
        foreach ( $entries as $name => $data )
            file_put_contents( "$dir/$name.php", '<?php return ' . var_export( $data + array( 'expires' => time() + 60 ), true ) . ';' );

        $this->assertSame( 1, expLayoutsCoreTransferImporter::clearResolverCacheFor( array( 29 ), array(), $dir ) );
        $this->assertFileDoesNotExist( "$dir/a.php" );
        $this->assertFileExists( "$dir/c.php" );

        // A written rule also removes the remembered "no rule matched" answers.
        $this->assertSame( 2, expLayoutsCoreTransferImporter::clearResolverCacheFor( array(), array( 18 ), $dir ) );
        $this->assertSame( array( "$dir/b.php" ), glob( "$dir/*.php" ) );

        unlink( "$dir/b.php" );
        rmdir( $dir );
    }
}
