<?php
/**
 * Node and object ids of a layouts transfer, mapped by remote id.
 *
 * An export records the remote id of each node and object a layout refers to
 * (collection items, query parents, rule targets); an import finds the ids
 * with the same remote ids on the target installation. The four lookups are
 * callables so the mapping can be tested without a database; the defaults
 * use the content kernel.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */
class expLayoutsCoreTransferReferences
{
    /** @var callable[] node_remote, object_remote, node_by_remote, object_by_remote, node_path */
    protected $lookups;

    protected $memo = array();

    public function __construct( array $lookups = array() )
    {
        $this->lookups = $lookups + self::kernelLookups();
    }

    public static function kernelLookups()
    {
        return array(
            'node_remote' => function ( $id )
            {
                $node = eZContentObjectTreeNode::fetch( (int)$id );
                return $node instanceof eZContentObjectTreeNode ? (string)$node->attribute( 'remote_id' ) : null;
            },
            'node_path' => function ( $id )
            {
                $node = eZContentObjectTreeNode::fetch( (int)$id );
                return $node instanceof eZContentObjectTreeNode ? (string)$node->attribute( 'path_identification_string' ) : null;
            },
            'object_remote' => function ( $id )
            {
                $object = eZContentObject::fetch( (int)$id );
                return $object instanceof eZContentObject ? (string)$object->attribute( 'remote_id' ) : null;
            },
            'node_by_remote' => function ( $remoteId )
            {
                $node = eZContentObjectTreeNode::fetchByRemoteID( (string)$remoteId );
                return $node instanceof eZContentObjectTreeNode ? (int)$node->attribute( 'node_id' ) : null;
            },
            'object_by_remote' => function ( $remoteId )
            {
                $object = eZContentObject::fetchByRemoteID( (string)$remoteId );
                return $object instanceof eZContentObject ? (int)$object->attribute( 'id' ) : null;
            },
        );
    }

    protected function call( $name, $argument )
    {
        $key = $name . '|' . $argument;
        if ( !array_key_exists( $key, $this->memo ) )
            $this->memo[$key] = call_user_func( $this->lookups[$name], $argument );
        return $this->memo[$key];
    }

    /**
     * The entry references.json keeps for a reference of this installation.
     */
    public function describe( $kind, $id )
    {
        if ( $kind === 'node' )
            return array( 'remote_id' => $this->call( 'node_remote', (int)$id ), 'path' => $this->call( 'node_path', (int)$id ) );
        return array( 'remote_id' => $this->call( 'object_remote', (int)$id ) );
    }

    /**
     * The remote id of a reference on this installation, or null.
     */
    public function localRemoteId( $kind, $id )
    {
        return $this->call( $kind === 'node' ? 'node_remote' : 'object_remote', (int)$id );
    }

    /**
     * The id on this installation for a reference of an archive.
     *
     * @param array $table the archive's references.json
     * @return int|null null when the reference cannot be mapped
     */
    public function map( $kind, $id, array $table )
    {
        $remoteId = isset( $table[$kind][(string)$id]['remote_id'] ) ? $table[$kind][(string)$id]['remote_id'] : null;
        if ( !is_string( $remoteId ) || $remoteId === '' )
            return null;
        $found = $this->call( $kind === 'node' ? 'node_by_remote' : 'object_by_remote', $remoteId );
        return $found === null ? null : (int)$found;
    }

    /**
     * The archive's remote id for a reference, or null.
     */
    public static function archiveRemoteId( $kind, $id, array $table )
    {
        $remoteId = isset( $table[$kind][(string)$id]['remote_id'] ) ? $table[$kind][(string)$id]['remote_id'] : null;
        return is_string( $remoteId ) && $remoteId !== '' ? $remoteId : null;
    }
}
