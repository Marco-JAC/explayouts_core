<?php
/**
 * The layouts transfer file: a ZIP holding JSON documents and a manifest.
 *
 * Needs no database: it writes and reads the archive, computes and checks the
 * checksums and validates the documents against the format's schema. The
 * format itself is described in doc/TRANSFER.md of this extension.
 *
 *   manifest.json    format, format_version, created, generator, source,
 *                    selection, locale, counts, files (sha256 and bytes of each)
 *   layouts.json     { "layouts": [ layout, ... ] }
 *   rules.json       { "rules": [ rule, ... ] }
 *   references.json  { "node": { "<id>": { "remote_id", "path" } },
 *                      "object": { "<id>": { "remote_id" } } }
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */
class expLayoutsCoreTransferFormat
{
    const FORMAT = 'exponential-layouts-transfer';

    /** The version this code writes; it reads every version from 1 up to this one. */
    const VERSION = 1;

    const MANIFEST = 'manifest.json';

    /** Largest uncompressed entry read from an archive (a guard against archives that expand without end). */
    const MAX_ENTRY_BYTES = 67108864;

    /** Most layouts, rules or blocks one archive may carry. */
    const MAX_ITEMS = 20000;

    const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.\-]{0,254}$/';

    public static function documentNames()
    {
        return array( 'layouts.json', 'rules.json', 'references.json' );
    }

    public static function strategies()
    {
        return array( 'skip', 'overwrite', 'copy' );
    }

    public static function encode( $data )
    {
        return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) . "\n";
    }

    /**
     * The counts the manifest states, taken from the documents.
     */
    public static function counts( array $documents )
    {
        $counts = array( 'layouts' => 0, 'shared_layouts' => 0, 'drafts' => 0, 'zones' => 0, 'linked_zones' => 0,
                         'blocks' => 0, 'parameters' => 0, 'collections' => 0, 'items' => 0, 'queries' => 0,
                         'rules' => 0, 'targets' => 0, 'conditions' => 0, 'node_references' => 0, 'object_references' => 0 );

        $layouts = isset( $documents['layouts.json']['layouts'] ) && is_array( $documents['layouts.json']['layouts'] ) ? $documents['layouts.json']['layouts'] : array();
        foreach ( $layouts as $layout )
        {
            $counts['layouts']++;
            if ( !empty( $layout['shared'] ) )
                $counts['shared_layouts']++;
            if ( isset( $layout['status'] ) && $layout['status'] === 'draft' )
                $counts['drafts']++;
            foreach ( isset( $layout['zones'] ) ? (array)$layout['zones'] : array() as $zone )
            {
                $counts['zones']++;
                if ( !empty( $zone['link'] ) )
                    $counts['linked_zones']++;
                self::countBlocks( isset( $zone['blocks'] ) ? (array)$zone['blocks'] : array(), $counts );
            }
        }

        $rules = isset( $documents['rules.json']['rules'] ) && is_array( $documents['rules.json']['rules'] ) ? $documents['rules.json']['rules'] : array();
        foreach ( $rules as $rule )
        {
            $counts['rules']++;
            $counts['targets'] += isset( $rule['targets'] ) ? count( (array)$rule['targets'] ) : 0;
            $counts['conditions'] += isset( $rule['conditions'] ) ? count( (array)$rule['conditions'] ) : 0;
        }

        $references = isset( $documents['references.json'] ) && is_array( $documents['references.json'] ) ? $documents['references.json'] : array();
        $counts['node_references'] = isset( $references['node'] ) ? count( (array)$references['node'] ) : 0;
        $counts['object_references'] = isset( $references['object'] ) ? count( (array)$references['object'] ) : 0;

        return $counts;
    }

    protected static function countBlocks( array $blocks, array &$counts )
    {
        foreach ( $blocks as $block )
        {
            $counts['blocks']++;
            $counts['parameters'] += isset( $block['parameters'] ) ? count( (array)$block['parameters'] ) : 0;
            foreach ( isset( $block['collections'] ) ? (array)$block['collections'] : array() as $collection )
            {
                $counts['collections']++;
                $counts['items'] += isset( $collection['items'] ) ? count( (array)$collection['items'] ) : 0;
                if ( !empty( $collection['query'] ) )
                    $counts['queries']++;
            }
            self::countBlocks( isset( $block['children'] ) ? (array)$block['children'] : array(), $counts );
        }
    }

    /**
     * The encoded files of an archive, manifest.json last: name => bytes.
     *
     * @param array $documents layouts.json, rules.json and references.json as arrays
     * @param array $meta created, generator, source, selection, locale (the rest of the manifest)
     */
    public static function files( array $documents, array $meta = array() )
    {
        $files = array();
        $index = array();
        foreach ( self::documentNames() as $name )
        {
            $data = isset( $documents[$name] ) ? $documents[$name] : self::emptyDocument( $name );
            $bytes = self::encode( $data );
            $files[$name] = $bytes;
            $index[$name] = array( 'sha256' => hash( 'sha256', $bytes ), 'bytes' => strlen( $bytes ) );
        }

        $manifest = array(
            'format' => self::FORMAT,
            'format_version' => self::VERSION,
            'created' => isset( $meta['created'] ) ? (string)$meta['created'] : gmdate( 'c' ),
            'generator' => isset( $meta['generator'] ) ? $meta['generator'] : array( 'name' => 'explayouts_core' ),
            'source' => isset( $meta['source'] ) ? $meta['source'] : array(),
            'selection' => isset( $meta['selection'] ) ? $meta['selection'] : array(),
            'locale' => isset( $meta['locale'] ) ? $meta['locale'] : array( 'main' => 'eng', 'translations' => array( 'eng' ) ),
            'counts' => self::counts( $documents ),
            'files' => $index,
        );
        $files[self::MANIFEST] = self::encode( $manifest );
        return $files;
    }

    public static function emptyDocument( $name )
    {
        switch ( $name )
        {
            case 'layouts.json': return array( 'layouts' => array() );
            case 'rules.json': return array( 'rules' => array() );
            default: return array( 'node' => new stdClass(), 'object' => new stdClass() );
        }
    }

    /**
     * Writes the archive to $path. Returns true, or an error message.
     */
    public static function writeArchive( $path, array $files )
    {
        if ( !class_exists( 'ZipArchive' ) )
            return 'The PHP zip extension is not available.';

        $zip = new ZipArchive();
        if ( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true )
            return 'The archive could not be created.';
        foreach ( $files as $name => $bytes )
            $zip->addFromString( $name, $bytes );
        return $zip->close() ? true : 'The archive could not be written.';
    }

    /**
     * Reads and validates an archive.
     *
     * @return array ok (bool), errors (list of messages), warnings, manifest, documents (name => decoded array)
     */
    public static function readArchive( $path )
    {
        $result = array( 'ok' => false, 'errors' => array(), 'warnings' => array(), 'manifest' => null, 'documents' => array() );

        if ( !class_exists( 'ZipArchive' ) )
        {
            $result['errors'][] = 'The PHP zip extension is not available.';
            return $result;
        }
        if ( !is_file( $path ) || !is_readable( $path ) )
        {
            $result['errors'][] = 'The file cannot be read.';
            return $result;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $path, ZipArchive::RDONLY ) !== true )
        {
            $result['errors'][] = 'The file is not a ZIP archive.';
            return $result;
        }

        $files = array();
        $allowed = array_merge( array( self::MANIFEST ), self::documentNames() );
        for ( $i = 0; $i < $zip->numFiles; $i++ )
        {
            $stat = $zip->statIndex( $i );
            $name = $stat['name'];
            if ( !in_array( $name, $allowed, true ) )
            {
                $result['warnings'][] = 'An unknown entry was ignored: ' . $name;
                continue;
            }
            if ( $stat['size'] > self::MAX_ENTRY_BYTES )
            {
                $result['errors'][] = 'The entry ' . $name . ' is too large.';
                continue;
            }
            $bytes = $zip->getFromIndex( $i );
            if ( $bytes === false || strlen( $bytes ) !== (int)$stat['size'] )
            {
                $result['errors'][] = 'The entry ' . $name . ' cannot be read.';
                continue;
            }
            $files[$name] = $bytes;
        }
        $zip->close();

        if ( $result['errors'] )
            return $result;

        return self::readFiles( $files, $result['warnings'] );
    }

    /**
     * Validates the files of an archive (name => bytes): manifest first, then
     * each document's checksum, then the documents' schema.
     */
    public static function readFiles( array $files, array $warnings = array() )
    {
        $result = array( 'ok' => false, 'errors' => array(), 'warnings' => $warnings, 'manifest' => null, 'documents' => array() );

        if ( !isset( $files[self::MANIFEST] ) )
        {
            $result['errors'][] = 'The archive has no manifest.json.';
            return $result;
        }
        $manifest = json_decode( $files[self::MANIFEST], true );
        if ( !is_array( $manifest ) )
        {
            $result['errors'][] = 'manifest.json is not valid JSON.';
            return $result;
        }
        $result['manifest'] = $manifest;

        $errors = self::validateManifest( $manifest );
        if ( $errors )
        {
            $result['errors'] = $errors;
            return $result;
        }

        foreach ( self::documentNames() as $name )
        {
            if ( !isset( $manifest['files'][$name] ) )
            {
                $result['errors'][] = 'manifest.json does not list ' . $name . '.';
                continue;
            }
            if ( !isset( $files[$name] ) )
            {
                $result['errors'][] = 'The archive has no ' . $name . '.';
                continue;
            }
            $expected = (string)( isset( $manifest['files'][$name]['sha256'] ) ? $manifest['files'][$name]['sha256'] : '' );
            if ( !hash_equals( strtolower( $expected ), hash( 'sha256', $files[$name] ) ) )
            {
                $result['errors'][] = 'The checksum of ' . $name . ' does not match the manifest.';
                continue;
            }
            $data = json_decode( $files[$name], true );
            if ( !is_array( $data ) )
            {
                $result['errors'][] = $name . ' is not valid JSON.';
                continue;
            }
            $result['documents'][$name] = $data;
        }
        if ( $result['errors'] )
            return $result;

        $result['errors'] = self::validateDocuments( $result['documents'] );
        if ( !$result['errors'] && isset( $manifest['counts'] ) && is_array( $manifest['counts'] ) )
        {
            $actual = self::counts( $result['documents'] );
            foreach ( array( 'layouts', 'blocks', 'rules' ) as $key )
            {
                if ( isset( $manifest['counts'][$key] ) && (int)$manifest['counts'][$key] !== $actual[$key] )
                    $result['errors'][] = 'The manifest counts ' . (int)$manifest['counts'][$key] . ' ' . $key . ', the archive holds ' . $actual[$key] . '.';
            }
        }
        $result['ok'] = !$result['errors'];
        return $result;
    }

    public static function validateManifest( $manifest )
    {
        $errors = array();
        if ( !is_array( $manifest ) )
            return array( 'manifest.json is not an object.' );
        if ( !isset( $manifest['format'] ) || $manifest['format'] !== self::FORMAT )
            $errors[] = 'This is not a layouts transfer file (format "' . self::FORMAT . '" expected).';
        if ( !isset( $manifest['format_version'] ) || !is_int( $manifest['format_version'] ) )
            $errors[] = 'manifest.json has no format_version.';
        elseif ( $manifest['format_version'] < 1 || $manifest['format_version'] > self::VERSION )
            $errors[] = 'Format version ' . $manifest['format_version'] . ' is not supported; this installation reads versions 1 to ' . self::VERSION . '.';
        if ( !isset( $manifest['files'] ) || !is_array( $manifest['files'] ) )
            $errors[] = 'manifest.json has no files list.';
        return $errors;
    }

    /**
     * Checks the documents against the schema of the format. Returns a list of
     * messages, empty when they are valid.
     */
    public static function validateDocuments( array $documents )
    {
        $errors = array();

        $layouts = isset( $documents['layouts.json']['layouts'] ) ? $documents['layouts.json']['layouts'] : null;
        if ( !is_array( $layouts ) || ( $layouts && array_keys( $layouts ) !== range( 0, count( $layouts ) - 1 ) ) )
            return array( 'layouts.json must hold a "layouts" list.' );
        if ( count( $layouts ) > self::MAX_ITEMS )
            return array( 'layouts.json holds too many layouts.' );

        $published = array();
        $seen = array();
        foreach ( $layouts as $index => $layout )
        {
            $where = 'layout #' . ( $index + 1 );
            if ( !is_array( $layout ) )
            {
                $errors[] = $where . ' is not an object.';
                continue;
            }
            if ( !isset( $layout['identifier'] ) || !is_string( $layout['identifier'] ) || !preg_match( self::IDENTIFIER_PATTERN, $layout['identifier'] ) )
            {
                $errors[] = $where . ' has no valid identifier.';
                continue;
            }
            $where = 'layout "' . $layout['identifier'] . '"';
            foreach ( array( 'name', 'layout_type' ) as $key )
                if ( !isset( $layout[$key] ) || !is_string( $layout[$key] ) )
                    $errors[] = $where . ': "' . $key . '" must be a string.';
            if ( !isset( $layout['shared'] ) || !is_bool( $layout['shared'] ) )
                $errors[] = $where . ': "shared" must be true or false.';
            if ( !isset( $layout['status'] ) || !in_array( $layout['status'], array( 'published', 'draft' ), true ) )
                $errors[] = $where . ': "status" must be "published" or "draft".';
            else
            {
                $key = $layout['identifier'] . '|' . $layout['status'];
                if ( isset( $seen[$key] ) )
                    $errors[] = $where . ' appears twice with status ' . $layout['status'] . '.';
                $seen[$key] = true;
                if ( $layout['status'] === 'published' )
                    $published[$layout['identifier']] = !empty( $layout['shared'] );
            }
            if ( !isset( $layout['zones'] ) || !is_array( $layout['zones'] ) )
            {
                $errors[] = $where . ': "zones" must be a list.';
                continue;
            }
            $zoneNames = array();
            foreach ( $layout['zones'] as $zone )
            {
                if ( !is_array( $zone ) || !isset( $zone['identifier'] ) || !is_string( $zone['identifier'] ) || $zone['identifier'] === '' )
                {
                    $errors[] = $where . ' has a zone without an identifier.';
                    continue;
                }
                if ( isset( $zoneNames[$zone['identifier']] ) )
                    $errors[] = $where . ' has the zone "' . $zone['identifier'] . '" twice.';
                $zoneNames[$zone['identifier']] = true;
                if ( isset( $zone['position'] ) && !is_int( $zone['position'] ) )
                    $errors[] = $where . ', zone "' . $zone['identifier'] . '": "position" must be a number.';
                if ( isset( $zone['link'] ) && $zone['link'] !== null )
                {
                    if ( !is_array( $zone['link'] ) || !isset( $zone['link']['layout'], $zone['link']['zone'] )
                         || !is_string( $zone['link']['layout'] ) || !is_string( $zone['link']['zone'] ) || $zone['link']['zone'] === '' )
                        $errors[] = $where . ', zone "' . $zone['identifier'] . '": "link" needs "layout" and "zone".';
                }
                if ( !isset( $zone['blocks'] ) || !is_array( $zone['blocks'] ) )
                    $errors[] = $where . ', zone "' . $zone['identifier'] . '": "blocks" must be a list.';
                else
                    self::validateBlocks( $zone['blocks'], $where . ', zone "' . $zone['identifier'] . '"', $errors, 0 );
            }
        }

        // Every link names a published layout of the archive: an export always
        // carries the shared layouts its layouts link to.
        foreach ( $layouts as $layout )
        {
            if ( !is_array( $layout ) || !isset( $layout['zones'] ) || !is_array( $layout['zones'] ) )
                continue;
            foreach ( $layout['zones'] as $zone )
            {
                if ( !is_array( $zone ) || empty( $zone['link'] ) || !is_array( $zone['link'] ) || !isset( $zone['link']['layout'] ) )
                    continue;
                if ( !isset( $published[$zone['link']['layout']] ) )
                    $errors[] = 'layout "' . ( isset( $layout['identifier'] ) ? $layout['identifier'] : '?' ) . '" links to "' . $zone['link']['layout'] . '", which the archive does not carry.';
            }
        }

        $rules = isset( $documents['rules.json']['rules'] ) ? $documents['rules.json']['rules'] : null;
        if ( !is_array( $rules ) )
            $errors[] = 'rules.json must hold a "rules" list.';
        else
        {
            if ( count( $rules ) > self::MAX_ITEMS )
                $errors[] = 'rules.json holds too many rules.';
            foreach ( $rules as $index => $rule )
            {
                $where = 'rule #' . ( $index + 1 );
                if ( !is_array( $rule ) || !isset( $rule['layout'] ) || !is_string( $rule['layout'] ) )
                {
                    $errors[] = $where . ' names no layout.';
                    continue;
                }
                if ( !isset( $published[$rule['layout']] ) )
                    $errors[] = $where . ' maps to "' . $rule['layout'] . '", which the archive does not carry as a published layout.';
                if ( isset( $rule['priority'] ) && !is_int( $rule['priority'] ) )
                    $errors[] = $where . ': "priority" must be a number.';
                foreach ( array( 'targets', 'conditions' ) as $list )
                {
                    if ( !isset( $rule[$list] ) || !is_array( $rule[$list] ) )
                    {
                        $errors[] = $where . ': "' . $list . '" must be a list.';
                        continue;
                    }
                    foreach ( $rule[$list] as $entry )
                        if ( !is_array( $entry ) || !isset( $entry['type'] ) || !is_string( $entry['type'] ) || !array_key_exists( 'value', $entry ) || !is_scalar( $entry['value'] ) )
                            $errors[] = $where . ': every entry of "' . $list . '" needs a type and a value.';
                }
            }
        }

        $references = isset( $documents['references.json'] ) ? $documents['references.json'] : null;
        if ( !is_array( $references ) )
            $errors[] = 'references.json must be an object.';
        else
        {
            foreach ( array( 'node', 'object' ) as $kind )
            {
                if ( !isset( $references[$kind] ) || !is_array( $references[$kind] ) )
                {
                    $errors[] = 'references.json needs a "' . $kind . '" object.';
                    continue;
                }
                foreach ( $references[$kind] as $id => $entry )
                    if ( !ctype_digit( (string)$id ) || !is_array( $entry ) || !array_key_exists( 'remote_id', $entry ) )
                        $errors[] = 'references.json: ' . $kind . ' ' . $id . ' needs a remote_id.';
            }
        }

        return array_slice( $errors, 0, 200 );
    }

    protected static function validateBlocks( array $blocks, $where, array &$errors, $depth )
    {
        if ( $depth > 20 )
        {
            $errors[] = $where . ': blocks are nested too deeply.';
            return;
        }
        foreach ( $blocks as $block )
        {
            // The definition may be empty: the table's default is '', and installations have such blocks.
            if ( !is_array( $block ) || !isset( $block['definition_identifier'] ) || !is_string( $block['definition_identifier'] ) )
            {
                $errors[] = $where . ' has a block without a definition_identifier.';
                continue;
            }
            $here = $where . ', block "' . ( isset( $block['name'] ) && is_string( $block['name'] ) && $block['name'] !== '' ? $block['name'] : $block['definition_identifier'] ) . '"';
            if ( isset( $block['parameters'] ) && !is_array( $block['parameters'] ) )
                $errors[] = $here . ': "parameters" must be an object.';
            elseif ( isset( $block['parameters'] ) )
                foreach ( $block['parameters'] as $name => $value )
                    if ( !is_string( $name ) && !is_int( $name ) || ( $value !== null && !is_scalar( $value ) ) )
                        $errors[] = $here . ': parameter values must be text.';
            foreach ( isset( $block['collections'] ) && is_array( $block['collections'] ) ? $block['collections'] : array() as $collection )
            {
                if ( !is_array( $collection ) || !isset( $collection['items'] ) || !is_array( $collection['items'] ) )
                {
                    $errors[] = $here . ': a collection needs an "items" list.';
                    continue;
                }
                foreach ( $collection['items'] as $item )
                    if ( !is_array( $item ) || !isset( $item['value_type'] ) || !is_string( $item['value_type'] ) || !isset( $item['value_id'] ) || !is_int( $item['value_id'] ) )
                        $errors[] = $here . ': every collection item needs a value_type and a numeric value_id.';
                if ( isset( $collection['query'] ) && $collection['query'] !== null
                     && ( !is_array( $collection['query'] ) || !isset( $collection['query']['query_type'] ) || !is_string( $collection['query']['query_type'] ) ) )
                    $errors[] = $here . ': a collection query needs a query_type.';
            }
            if ( isset( $block['children'] ) && is_array( $block['children'] ) )
                self::validateBlocks( $block['children'], $here, $errors, $depth + 1 );
        }
    }

    /**
     * Splits a reference ("node:89", "object:5") into kind and id, or false.
     */
    public static function parseReference( $reference )
    {
        if ( !is_string( $reference ) || !preg_match( '/^(node|object):([0-9]+)$/', $reference, $m ) )
            return false;
        return array( $m[1], (int)$m[2] );
    }
}
