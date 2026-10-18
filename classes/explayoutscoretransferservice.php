<?php
/**
 * Export and import of layouts, shared layouts and rules: the one entry point
 * the admin pages (explayouts_ui/transfer_export, explayouts_ui/transfer_import)
 * and the console command (exp:layouts) use, so both do the same.
 *
 *   $service = new expLayoutsCoreTransferService();
 *   $export = $service->export( array( 'layouts' => array( 'home' ), 'rules' => 'selected' ) );
 *   $preview = $service->preview( $export['path'], array( 'strategy' => 'copy' ) );
 *   $result = $service->import( $export['path'], array( 'strategy' => 'copy', 'dry_run' => false ) );
 *
 * The file format is described in doc/TRANSFER.md of this extension.
 *
 * @copyright Copyright (C) 1998 - 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */
class expLayoutsCoreTransferService
{
    const AUDIT_EXPORT = 'data.explayouts_core.export';
    const AUDIT_IMPORT = 'data.explayouts_core.import';

    protected $workDir;

    /** @var expLayoutsCoreTransferReferences */
    protected $references;

    public function __construct( $workDir = null, ?expLayoutsCoreTransferReferences $references = null )
    {
        $this->workDir = $workDir !== null ? rtrim( $workDir, '/' ) : self::defaultWorkDir();
        $this->references = $references ? $references : new expLayoutsCoreTransferReferences();
    }

    /** Where exports are written and uploads wait for their import: var/<site>/cache/explayouts/transfer. */
    public static function defaultWorkDir()
    {
        return eZINI::instance( 'site.ini' )->variable( 'FileSettings', 'VarDir' ) . '/cache/explayouts/transfer';
    }

    public function workDir()
    {
        if ( !is_dir( $this->workDir ) )
            eZDir::mkdir( $this->workDir, false, true );
        return $this->workDir;
    }

    /**
     * Removes work files older than a day (exports already downloaded,
     * uploads whose preview was never imported).
     */
    public function removeStaleFiles( $maxAge = 86400 )
    {
        if ( !is_dir( $this->workDir ) )
            return 0;
        $removed = 0;
        foreach ( glob( $this->workDir . '/*.zip' ) ?: array() as $file )
            if ( filemtime( $file ) < time() - $maxAge && @unlink( $file ) )
                $removed++;
        return $removed;
    }

    public static function generatorVersion()
    {
        if ( class_exists( 'explayouts_coreInfo' ) )
        {
            $info = explayouts_coreInfo::info();
            return isset( $info['Version'] ) ? (string)$info['Version'] : '';
        }
        return '';
    }

    public static function sourceDescription()
    {
        $ini = eZINI::instance( 'site.ini' );
        return array(
            'site_name' => (string)$ini->variable( 'SiteSettings', 'SiteName' ),
            'site_url' => (string)$ini->variable( 'SiteSettings', 'SiteURL' ),
            'exponential_version' => class_exists( 'eZPublishSDK' ) ? (string)eZPublishSDK::version() : '',
            'database' => (string)$ini->variable( 'DatabaseSettings', 'DatabaseImplementation' ),
        );
    }

    /**
     * Exports a selection (see expLayoutsCoreTransferExporter::normaliseSelection())
     * into a ZIP file.
     *
     * @param string|null $path where to write it; null for a new file in the work directory
     * @return array ok, errors, warnings, path, filename, manifest
     */
    public function export( array $selection, $path = null )
    {
        $exporter = new expLayoutsCoreTransferExporter( $this->references );
        $selection = expLayoutsCoreTransferExporter::normaliseSelection( $selection );
        $documents = $exporter->export( $selection );
        $result = array( 'ok' => false, 'errors' => array(), 'warnings' => $exporter->warnings(), 'path' => null, 'filename' => null, 'manifest' => null );

        if ( !$documents['layouts.json']['layouts'] )
        {
            $result['errors'][] = 'Nothing was selected to export.';
            return $result;
        }

        $files = expLayoutsCoreTransferFormat::files( $documents, array(
            'generator' => array( 'name' => 'explayouts_core', 'version' => self::generatorVersion() ),
            'source' => self::sourceDescription(),
            'selection' => $selection,
            'locale' => array( 'main' => 'eng', 'translations' => array( 'eng' ) ),
        ) );

        $filename = 'layouts-' . gmdate( 'Ymd-His' ) . '.zip';
        if ( $path === null )
            $path = $this->workDir() . '/export-' . bin2hex( random_bytes( 8 ) ) . '.zip';
        $written = expLayoutsCoreTransferFormat::writeArchive( $path, $files );
        if ( $written !== true )
        {
            $result['errors'][] = $written;
            return $result;
        }

        $result['ok'] = true;
        $result['path'] = $path;
        $result['filename'] = $filename;
        $result['manifest'] = json_decode( $files[expLayoutsCoreTransferFormat::MANIFEST], true );

        self::audit( self::AUDIT_EXPORT, array(
            'object' => array( 'type' => 'explayouts', 'name' => $filename ),
            'after' => array( 'selection' => $selection, 'counts' => $result['manifest']['counts'] ),
            'result' => 'success',
        ) );
        return $result;
    }

    /**
     * What this installation holds, in the documents' shape, for the diff.
     */
    public function targetDocuments()
    {
        $exporter = new expLayoutsCoreTransferExporter( $this->references );
        // Through JSON, so both sides of the diff have the same shape.
        return json_decode( json_encode( $exporter->exportEverything() ), true );
    }

    /**
     * Reads, validates and plans an import without writing anything.
     *
     * @param array $options strategy, items, only (identifiers), publish, unmapped
     * @return array ok, errors, warnings, manifest, plan (layouts, rules, totals, each item with its action), unmapped
     */
    public function preview( $path, array $options = array() )
    {
        $read = expLayoutsCoreTransferFormat::readArchive( $path );
        $result = array( 'ok' => false, 'errors' => $read['errors'], 'warnings' => $read['warnings'], 'manifest' => $read['manifest'], 'plan' => null, 'unmapped' => array() );
        if ( !$read['ok'] )
            return $result;

        $only = isset( $options['only'] ) && is_array( $options['only'] ) && $options['only'] ? array_values( $options['only'] ) : null;
        if ( $only !== null )
        {
            $carried = array();
            foreach ( $read['documents']['layouts.json']['layouts'] as $layout )
                $carried[$layout['identifier']] = true;
            foreach ( $only as $identifier )
                if ( !isset( $carried[$identifier] ) )
                    $result['errors'][] = 'The file does not carry the layout "' . $identifier . '".';
            if ( $result['errors'] )
                return $result;
        }

        $plan = expLayoutsCoreTransferDiff::plan( $read['documents'], $this->targetDocuments(), $only );
        $strategy = isset( $options['strategy'] ) && in_array( $options['strategy'], expLayoutsCoreTransferFormat::strategies(), true ) ? $options['strategy'] : 'skip';
        $perItem = isset( $options['items'] ) && is_array( $options['items'] ) ? $options['items'] : array();
        foreach ( array( 'layouts', 'rules' ) as $list )
            foreach ( $plan[$list] as $index => $item )
                $plan[$list][$index]['action'] = expLayoutsCoreTransferDiff::action( $item, $strategy, $perItem );

        $importer = new expLayoutsCoreTransferImporter( $this->references );
        $mapping = $importer->mapReferences( $read['documents'] );

        $result['ok'] = true;
        $result['plan'] = $plan;
        $result['unmapped'] = $mapping['unmapped'];
        $result['documents'] = $read['documents'];
        return $result;
    }

    /**
     * Imports a file. With dry_run (the default) it only returns the preview.
     *
     * @param array $options strategy (skip, overwrite, copy), items (plan key => strategy), only, publish (bool),
     *                       unmapped (drop, keep, fail), dry_run (bool, default true)
     * @return array the preview, plus "import" (the importer's report) when it wrote
     */
    public function import( $path, array $options = array() )
    {
        $preview = $this->preview( $path, $options );
        $dryRun = !array_key_exists( 'dry_run', $options ) || !empty( $options['dry_run'] );
        if ( !$preview['ok'] || $dryRun )
        {
            unset( $preview['documents'] );
            return $preview;
        }

        $importer = new expLayoutsCoreTransferImporter( $this->references );
        $report = $importer->import( $preview['documents'], $preview['plan'], $options );
        unset( $preview['documents'] );
        $preview['import'] = $report;
        $preview['ok'] = $report['ok'];
        $preview['errors'] = array_merge( $preview['errors'], $report['errors'] );
        $preview['warnings'] = array_merge( $preview['warnings'], $report['warnings'] );

        $source = isset( $preview['manifest']['source'] ) ? $preview['manifest']['source'] : array();
        $parent = self::audit( self::AUDIT_IMPORT, array(
            'object' => array( 'type' => 'explayouts', 'name' => isset( $source['site_url'] ) ? (string)$source['site_url'] : '' ),
            'after' => array(
                'strategy' => isset( $options['strategy'] ) ? (string)$options['strategy'] : 'skip',
                'publish' => !empty( $options['publish'] ),
                'written' => count( $report['written'] ),
                'layouts' => $report['affected_layouts'],
                'rules' => $report['affected_rules'],
                'unmapped' => count( $preview['unmapped'] ),
            ),
            'result' => $report['ok'] ? 'success' : 'failed',
            'error' => $report['ok'] ? null : implode( ' ', $report['errors'] ),
        ) );
        foreach ( $report['written'] as $entry )
        {
            self::audit( self::AUDIT_IMPORT . '.item', array(
                'object' => array( 'type' => 'explayouts_' . $entry['kind'], 'id' => $entry['id'], 'name' => $entry['key'] ),
                'after' => array( 'action' => $entry['what'] ),
                'result' => 'success',
                'parent' => $parent,
            ) );
        }
        return $preview;
    }

    protected static function audit( $name, array $data )
    {
        if ( !class_exists( 'expAudit' ) )
            return null;
        $data = array_filter( $data, function ( $value ) { return $value !== null; } );
        return expAudit::event( $name, $data );
    }

    /**
     * The layouts of this installation for a list: one row per identifier and status.
     */
    public static function listLayouts( $withDrafts = true )
    {
        $ruleCounts = array();
        $rules = eZPersistentObject::fetchObjectList( expLayoutsRule::definition(), null, null, null, null, false );
        foreach ( is_array( $rules ) ? $rules : array() as $rule )
            $ruleCounts[(int)$rule['layout_id']] = ( isset( $ruleCounts[(int)$rule['layout_id']] ) ? $ruleCounts[(int)$rule['layout_id']] : 0 ) + 1;

        $rows = array();
        foreach ( expLayoutsLayout::fetchList() ?: array() as $layout )
        {
            $status = (int)$layout->attribute( 'status' );
            if ( !$withDrafts && $status !== 2 )
                continue;
            $links = array();
            foreach ( expLayoutsZone::fetchByLayout( (int)$layout->attribute( 'id' ), $status ) as $zone )
            {
                if ( !$zone->isLinked() )
                    continue;
                $linked = expLayoutsLayout::fetch( (int)$zone->attribute( 'linked_layout_id' ) );
                if ( $linked )
                    $links[(string)$linked->attribute( 'identifier' )] = true;
            }
            $rows[] = array(
                'id' => (int)$layout->attribute( 'id' ),
                'identifier' => (string)$layout->attribute( 'identifier' ),
                'name' => (string)$layout->attribute( 'name' ),
                'layout_type' => (string)$layout->attribute( 'layout_type' ),
                'shared' => (int)$layout->attribute( 'shared' ) === 1,
                'status' => $status === 2 ? 'published' : 'draft',
                'rules' => isset( $ruleCounts[(int)$layout->attribute( 'id' )] ) ? $ruleCounts[(int)$layout->attribute( 'id' )] : 0,
                'links' => array_keys( $links ),
                'modified' => (int)$layout->attribute( 'modified' ),
            );
        }
        usort( $rows, function ( $a, $b )
        {
            if ( $a['shared'] !== $b['shared'] )
                return $a['shared'] ? -1 : 1;
            $c = strcasecmp( $a['name'], $b['name'] );
            return $c !== 0 ? $c : strcmp( $b['status'], $a['status'] );
        } );
        return $rows;
    }
}
