<?php
//
// Definition of eZDBFileHandlerOracleBackend class
//
// Created on: <03-May-2006 11:28:15 vs>
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ publish
// SOFTWARE RELEASE: 3.8.x
// COPYRIGHT NOTICE: Copyright (C) 1999-2006 eZ systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

/*! \file ezdbfilehandleroraclebackend.php

NOTE: this backend requires PECL/oci8 extension to function.
You can download it here: http://pecl.php.net/package/oci8

*/

/*
CREATE TABLE ezdbfile (
  id        INT PRIMARY KEY,
  name      VARCHAR(255) NOT NULL UNIQUE,
  name_hash VARCHAR(34)  NOT NULL UNIQUE,
  datatype  VARCHAR(60)  DEFAULT 'application/octet-stream' NOT NULL,
  scope     VARCHAR(20)  DEFAULT 'UNKNOWN' NOT NULL ,
  filesize  INT          DEFAULT 0 NOT NULL ,
  mtime     INT          DEFAULT 0 NOT NULL ,
  lob       BLOB
);

CREATE SEQUENCE s_dbfile;

CREATE OR REPLACE TRIGGER ezdbfile_id_tr
BEFORE INSERT ON ezdbfile FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_dbfile.nextval INTO :new.id FROM dual;
END;
/


*/

define( 'TABLE_METADATA',     'ezdbfile' );

require_once( 'lib/ezutils/classes/ezdebugsetting.php' );
require_once( 'lib/ezutils/classes/ezdebug.php' );

class eZDBFileHandlerOracleBackend
{
    function _connect()
    {
        if ( !function_exists( 'ocilogon' ) )
            die( "PECL oci8 extension (http://pecl.php.net/package/oci8) is required to use Oracle clustering functionality.\n" );

        if ( !isset( $GLOBALS['eZDBFileHandlerOracleBackend_dbparams'] ) )
        {
            $fileINI = eZINI::instance( 'file.ini' );

            $params['host']       = $fileINI->variable( 'ClusteringSettings', 'DBHost' );
            $params['port']       = $fileINI->variable( 'ClusteringSettings', 'DBPort' );
            $params['dbname']     = $fileINI->variable( 'ClusteringSettings', 'DBName' );
            $params['user']       = $fileINI->variable( 'ClusteringSettings', 'DBUser' );
            $params['pass']       = $fileINI->variable( 'ClusteringSettings', 'DBPassword' );
            $params['chunk_size'] = $fileINI->variable( 'ClusteringSettings', 'DBChunkSize' );

            $GLOBALS['eZDBFileHandlerOracleBackend_dbparams'] = $params;
        }
        else
            $params = $GLOBALS['eZDBFileHandlerOracleBackend_dbparams'];

        $this->db = @ocilogon( $params['user'], $params['pass'], $params['dbname'] );
        if ( !$this->db )
            $this->_die( "Unable to connect to storage server" );
        $this->dbparams = $params;
        //ociinternaldebug( 1 );
    }

    function _delete( $filePath, $insideOfTransaction = false )
    {
        // If the file does not exists then do nothing.
        $metaData = $this->_fetchMetadata( $filePath );
        if ( !$metaData )
            return true;

        // Delete file (transaction is started implicitly).
        $result = true;
        $sql = "DELETE FROM " . TABLE_METADATA . " WHERE id=" . $metaData['id'];
        $statement = ociparse( $this->db, $sql );
        if ( !@ociexecute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $result = false;
        }

        ocifreestatement( $statement );

        if ( !$insideOfTransaction )
        {
            if ( $result )
                ocicommit( $this->db );
            else
                ocirollback( $this->db );
        }

        return $result;
    }

    function _deleteByRegex( $regex )
    {
        $escapedRegex = $this->_escapeString( $regex );
        $sql = "DELETE FROM " . TABLE_METADATA . " WHERE REGEXP_LIKE( name, '$escapedRegex' )";
        $statement = ociparse( $this->db, $sql );

        $result = true;
        if ( !@ociexecute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $result = false;
        }

        ocifreestatement( $statement );

        if ( $result )
            ocicommit( $this->db );
        else
            ocirollback( $this->db );

        return $result;
    }

    function _deleteByWildcard( $wildcard )
    {
        // Convert wildcard to regexp.
        $wildcard = $this->_escapeString( $wildcard );
        $regex = '^' . $wildcard  . '$';

        $regex = str_replace( array( '.'  ),
                              array( '\.' ),
                              $regex );

        $regex = str_replace( array( '?', '*',  '{', '}', ',' ),
                              array( '.', '.*', '(', ')', '|' ),
                              $regex );

        $escapedRegex = $this->_escapeString( $regex );
        $sql = "DELETE FROM " . TABLE_METADATA . " WHERE REGEXP_LIKE( name, '$escapedRegex' )";
        $statement = ociparse( $this->db, $sql );

        $result = true;
        if ( !@ociexecute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $result = false;
        }

        ocifreestatement( $statement );

        if ( $result )
            ocicommit( $this->db );
        else
            ocirollback( $this->db );

        return $result;
    }

    function _deleteByLike( $like )
    {
        $like = $this->_escapeString( $like );
        $sql = "DELETE FROM " . TABLE_METADATA . " WHERE name like '$like'" ;
        $statement = ociparse( $this->db, $sql );

        $result = true;
        if ( !@ociexecute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            $result = false;
        }

        ocifreestatement( $statement );

        if ( $result )
            ocicommit( $this->db );
        else
            ocirollback( $this->db );

        return $result;
    }

    function _deleteByDirList( $dirList, $commonPath, $commonSuffix )
    {

        $result = true;
        foreach ( $dirList as $dirItem )
        {
            $sql = "DELETE FROM " . TABLE_METADATA . " WHERE name like '$commonPath/$dirItem/$commonSuffix%'" ;
            $statement = ociparse( $this->db, $sql );

            if ( !@ociexecute( $statement, OCI_DEFAULT ) )
            {
                $this->_error( $statement, $sql );
                $result = $result && false;
            }

	        ocifreestatement( $statement );

            // NB: we are committing/rollbacking after every single item. Is it a good idea?
            if ( $result )
                ocicommit( $this->db );
            else
                ocirollback( $this->db );
        }
        return $result;
    }


    function _exists( $filePath )
    {
        $filePathHash = md5( $filePath );
        $sql = "SELECT COUNT(*) AS count FROM " . TABLE_METADATA . " WHERE name_hash='$filePathHash'";
        $statement = ociparse( $this->db, $sql );
        //$result = true;
        if ( !ociexecute ( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            //$result = false;
            $row = array ( false ); // this is different from 0/1, in case caller wants to know...
        }
        else
        {
            ocifetchinto( $statement, $row, OCI_NUM );
        }
        //$count = $row[0];
        ocifreestatement( $statement );
        return $row[0];
    }

    function __mkdir_p( $dir )
    {
        // create parent directories
        $dirElements = explode( '/', $dir );
        if ( count( $dirElements ) == 0 )
            return true;

        $result = true;
        $currentDir = $dirElements[0];

        if ( $currentDir != '' && !file_exists( $currentDir ) && !mkdir( $currentDir, '0777' ))
            return false;

        for ( $i = 1; $i < count( $dirElements ); ++$i )
        {
            $dirElement = $dirElements[$i];
            if ( strlen( $dirElement ) == 0 )
                continue;

            $currentDir .= '/' . $dirElement;

            if ( !file_exists( $currentDir ) && !mkdir( $currentDir, 0777 ) )
                return false;

            $result = true;
        }

        return $result;
    }

    function _fetch( $filePath, $uniqueName = false )
    {
        // Check if the file exists in db.
        if ( !$this->_exists( $filePath ) )
        {
            eZDebug::writeNotice( "File '$filePath' does not exists while trying to fetch." );
            return false;
        }

        // Fetch LOB.
        if ( !( $lob = $this->_fetchLob( $filePath ) ) )
            return false;

        // Create temporary file.
        if ( strrpos( $filePath, '.' ) > 0 )
            $tmpFilePath = substr_replace( $filePath, getmypid().'tmp', strrpos( $filePath, '.' ), 0  );
        else
            $tmpFilePath = $filePath . '.' . getmypid().'tmp';

//        $tmpFilePath = $filePath.getmypid().'tmp';
        $this->__mkdir_p( dirname( $tmpFilePath ) );
        if ( !( $fp = fopen( $tmpFilePath, 'wb' ) ) )
        {
            eZDebug::writeError( "Cannot write to '$tmpFilePath' while fetching file." );
            $lob->free();
            return false;
        }

        // Read large object contents and write them to file.
        //$chunkSize = $this->dbparams['chunk_size'];
        //while ( $chunk = $lob->read( $chunkSize ) )
        $chunk = $lob->load();
        fwrite( $fp, $chunk );
        fclose( $fp );

        if ( !$uniqueName === true )
        {
            include_once( 'lib/ezfile/classes/ezfile.php' );
            eZFile::rename( $tmpFilePath, $filePath );
        }
        else
        {
            $filePath = $tmpFilePath;
        }

        $lob->free();
        return $filePath;
    }

    function _fetchContents( $filePath )
    {
        // Check if the file exists.
        if ( !$this->_exists( $filePath ) )
        {
            eZDebug::writeNotice( "File '$filePath' does not exists while trying to fetch its contents." );
            return false;
        }

        // Fetch large object.
        if ( !( $lob = $this->_fetchLob( $filePath ) ) )
            return false;

        $contents = $lob->load();
        $lob->free();
        return $contents;
    }

    function _fetchMetadata( $filePath )
    {
        $sql  = "SELECT id,name,name_hash,datatype,scope,filesize,mtime ";
        $sql .= "FROM " . TABLE_METADATA . " WHERE name_hash='" . md5( $filePath ) . "'" ;

        if ( !( $statement = ociparse ( $this->db, $sql ) ) || !ociexecute ( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            ocifreestatement( $statement );
            return false;
        }

        ocifetchstatement( $statement, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW );

        if ( ( $nrows = count( $rows ) ) > 1 )
            eZDebug::writeError( "Duplicate file '$filePath' found." );
        elseif ( $nrows == 0 )
        {
            ocifreestatement( $statement );
            return false;
        }

        ocifreestatement( $statement );
        $row = $rows[0];

        // Convert column names to lowercase.
        foreach ( $row as $key => $val )
        {
            $row[strtolower( $key )] = $val;
            unset( $row[$key] );
        }

        // Hide that Oracle cannot handle 'size' column.
        $row['size'] = $row['filesize'];
        unset( $row['filesize'] );

        return $row;
    }

    function _store( $filePath, $datatype, $scope )
    {
        if ( !is_readable( $filePath ) )
        {
            eZDebug::writeError( "Unable to store file '$filePath' since it is not readable.", 'ezdbfilehandleroraclebackend' );
            return false;
        }

        if ( !$fp = @fopen( $filePath, 'rb' ) )
        {
            eZDebug::writeError( "Cannot read '$filePath'.", 'ezdbfilehandleroraclebackend' );
            return false;
        }

        // Prepare file metadata for storing.
        $filePathHash = md5( $filePath );
        $filePathEscaped = $this->_escapeString( $filePath );
        $datatype = $this->_escapeString( $datatype );
        $scope = $this->_escapeString( $scope );
        $fileMTime = (int) filemtime( $filePath );
        $contentLength = (int) filesize( $filePath );

        // Transaction is started implicitly.

        // Check if a file with the same name already exists in db.
        if ( $row = $this->_fetchMetadata( $filePath ) ) // if it does
        {
            $sql  = "UPDATE " . TABLE_METADATA . " SET ";
            $sql .= "name='$filePathEscaped', name_hash='$filePathHash', ";
            $sql .= "datatype='$datatype', scope='$scope', ";
            $sql .= "filesize=$contentLength, mtime=$fileMTime, ";
            $sql .= "lob=EMPTY_BLOB() ";
            $sql .= "WHERE id=" . $row['id'];
        }
        else // else if it doesn't
        {
            // create file in db
            $sql  = "INSERT INTO " . TABLE_METADATA . " (name, name_hash, datatype, scope, filesize, mtime, lob) ";
            $sql .= "VALUES ('$filePathEscaped', '$filePathHash', '$datatype', '$scope', ";
            $sql .= "'$contentLength', '$fileMTime', EMPTY_BLOB())";
        }
        $sql .= " RETURNING lob INTO :lob";

        $statement = ociparse( $this->db, $sql );
        $lob = ocinewdescriptor( $this->db, OCI_D_LOB );
        ocibindbyname( $statement, ":lob", $lob, -1, OCI_B_BLOB );
        if ( !@ociexecute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            ocifreestatement( $statement );
            $lob->free();
            return false;
        }

        ocifreestatement( $statement );

        // Save large object.
        $chunkSize = $this->dbparams['chunk_size'];
        $start = 0;
        while ( !feof( $fp ) )
        {
            $chunk = fread( $fp, $chunkSize );

            // work around buggy implementation of lob->save
            if ( !feof( $fp ) )
                $chunk .= ' ';

            if ( @$lob->save( $chunk, $start+1 ) === false )
            {
                eZDebug::writeNotice( "Failed to write data chunk while storing file: " . $sql );
                fclose( $fp );
                $lob->free();
                ocirollback( $this->db );
                return false;
            }

           $start += $chunkSize;
        }
        fclose( $fp );
        $lob->free();

        // Commit DB transaction.
        ocicommit( $this->db );

        return true;
    }

    function _storeContents( $filePath, $contents, $scope, $datatype )
    {
        // Mostly cut&pasted from _store().

        // Prepare file metadata for storing.
        $filePathHash = md5( $filePath );
        $filePathEscaped = $this->_escapeString( $filePath );
        $datatype = $this->_escapeString( $datatype );
        $scope = $this->_escapeString( $scope );
        $fileMTime = time();
        $contentLength = strlen( $contents );

        // Transaction is started implicitly.

        // Check if a file with the same name already exists in db.
        if ( $row = $this->_fetchMetadata( $filePath ) ) // if it does
        {
            $sql  = "UPDATE " . TABLE_METADATA . " SET ";
            $sql .= "name='$filePathEscaped', name_hash='$filePathHash', ";
            $sql .= "datatype='$datatype', scope='$scope', ";
            $sql .= "filesize=$contentLength, mtime=$fileMTime, ";
            $sql .= "lob=EMPTY_BLOB() ";
            $sql .= "WHERE id=" . $row['id'];
        }
        else // else if it doesn't
        {
            // create file in db
            $sql  = "INSERT INTO " . TABLE_METADATA . " (name, name_hash, datatype, scope, filesize, mtime, lob) ";
            $sql .= "VALUES ('$filePathEscaped', '$filePathHash', '$datatype', '$scope', ";
            $sql .= "'$contentLength', '$fileMTime', EMPTY_BLOB())";
        }
        $sql .= " RETURNING lob INTO :lob";

        $statement = ociparse( $this->db, $sql );
        $lob = ocinewdescriptor( $this->db, OCI_D_LOB );
        ocibindbyname( $statement, ":lob", $lob, -1, OCI_B_BLOB );
        if ( !@ociexecute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
			ocifreestatement( $statement );
            $lob->free();
            ocirollback( $conn );
            return false;
        }

        ocifreestatement( $statement );

        // Save large object.
        $chunkSize = $this->dbparams['chunk_size'];
        for ( $pos = 0; $pos < $contentLength; $pos += $chunkSize )
        {
            $chunk = substr( $contents, $pos, $chunkSize );

            // work around buggy implementation of lob->save
            if ( $pos + $chunkSize < $contentLength )
                $chunk .= ' ';

            // catch warning generated by moving to $pos
            if ( @$lob->save( $chunk, $pos+1 ) === false )
            {
                eZDebug::writeNotice( "Failed to write data chunk while storing file contents: " . $sql );
                $lob->free();
                ocirollback( $this->db );
                return;
            }
        }
        $lob->free();

        // Commit DB transaction.
        ocicommit( $this->db );

        return true;
    }

    function _copy( $srcFilePath, $dstFilePath )
    {
        // Fetch source file metadata.
        $srcMetadata = $this->_fetchMetadata( $srcFilePath );
        if ( !$srcMetadata ) // if source file does not exist then do nothing.
            return false;

        // Delete destination file if exists.
        // NOTE: check for race conditions and deadlocks here. (???)
        if ( $this->_exists( $dstFilePath ) )
            $this->_delete( $dstFilePath, true );

        // Fetch source large object.
        if ( !( $srcLob = $this->_fetchLob( $srcFilePath ) ) )
            return false;

        // Insert destination metadata.
        $sql  = "INSERT INTO " . TABLE_METADATA . " (name, name_hash, datatype, scope, filesize, mtime, lob) VALUES ";
        $sql .= sprintf( "('%s', '%s', '%s', '%s', %d, %d, EMPTY_BLOB()) RETURNING lob INTO :lob",
                         $this->_escapeString( $dstFilePath ), md5( $dstFilePath ),
                         $srcMetadata['datatype'], $srcMetadata['scope'], $srcMetadata['size'], $srcMetadata['mtime'] );
        $statement = ociparse( $this->db, $sql );
        $dstLob = ocinewdescriptor( $this->db, OCI_D_LOB );
        ocibindbyname( $statement, ":lob", $dstLob, -1, OCI_B_BLOB );
        if ( !ociexecute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            ocifreestatement( $statement );
            $srcLob->free();
            $dstLob->free();
            ocirollback( $this->db );
            return false;
        }

        ocifreestatement( $statement );

        // Copy source large object data.
        //$chunkSize = $this->dbparams['chunk_size'];
        //while ( $chunk = $srcLob->read( $chunkSize ) )
        //{
            $chunk = $srcLob->load();
            if ( $dstLob->save( $chunk ) === false )
            {
                eZDebug::writeNotice( "Failed to write data chunk while storing file contents: " . $sql );
                $srcLob->free();
                $dstLob->free();
                ocirollback( $this->db );
                return false;
            }
        //}

        $srcLob->free();
        $dstLob->free();

        // Commit DB transaction.
        ocicommit( $this->db );

        return true;
    }

    function _linkCopy( $srcPath, $dstPath )
    {
        return $this->_copy( $srcPath, $dstPath );
    }

    function _rename( $srcFilePath, $dstFilePath )
    {
        // Check if source file exists.
        $srcMetadata = $this->_fetchMetadata( $srcFilePath );
        if ( !$srcMetadata )
        {
            // if doesn't then do nothing
            eZDebug::writeWarning( "File '$srcFilePath' to rename does not exist",
                                   'ezdbfilehandleroraclebackend' );
            return false;
        }

        // Delete destination file if exists.
        $dstMetadata = $this->_fetchMetadata( $dstFilePath );
        if ( $dstMetadata ) // if destination file exists
            $this->_delete( $dstFilePath, true );

        // Update source file metadata.
        $sql = sprintf( "UPDATE %s SET name='%s', name_hash='%s' WHERE id=%d",
                        TABLE_METADATA,
                        $this->_escapeString( $dstFilePath ), md5( $dstFilePath ),
                        $srcMetadata['id'] );
        $statement = ociparse( $this->db, $sql );
        if ( !ociexecute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            eZDebug::writeError( "Error renaming file '$srcFilePath'.", 'ezdbfilehandleroraclesqlbackend' );
            ocifreestatement( $statement );
            ocirollback( $this->db );
            return false;
        }

        ocifreestatement( $statement );
        ocicommit( $this->db );
        return true;
    }

    function _passThrough( $filePath )
    {
        if ( !$this->_exists( $filePath ) )
            return false;

        if ( !( $lob = $this->_fetchLob( $filePath ) ) )
            return false;

        //$chunkSize = $this->dbparams['chunk_size'];
        //while ( $chunk = $lob->read( $chunkSize ) )
        $chunk = $lob->load();
        echo $chunk;

        $lob->free();
        return true;
    }

    function _getFileList( $skipBinaryFiles, $skipImages )
    {
        $query = 'SELECT name FROM ' . TABLE_METADATA;

        // omit some file types if needed
        $filters = array();
        if ( $skipBinaryFiles )
            $filters[] = "'binaryfile'";
        if ( $skipImages )
            $filters[] = "'image'";
        if ( $filters )
            $query .= ' WHERE scope NOT IN (' . join( ', ', $filters ) . ')';

        $statement = ociparse( $this->db, $query );
        if ( !@ociexecute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $sql );
            ocifreestatement( $statement );
            return false;
        }

        $filePathList = array();
        while ( ocifetchinto( $statement, $row, OCI_NUM ) )
            $filePathList[] = $row[0];

        ocifreestatement( $statement );
        return $filePathList;
    }

    function _die( $msg, $sql = null )
    {
        $error = ocierror( $this->db );
        eZDebug::writeError( $sql, "$msg: " . $error['message'] );

        if( @include_once( '../bt.php' ) )
        {
            bt();
        }
        die( $msg );
    }

    /**
     * \private
     * \static
     */
    function _fetchLob( $filePath )
    {
        $query = 'SELECT lob FROM ' . TABLE_METADATA . " WHERE name_hash = '" . md5( $filePath ) . "'";
        $statement = ociparse( $this->db, $query );
        if ( !ociexecute( $statement, OCI_DEFAULT ) )
        {
            $this->_error( $statement, $query );
            return false;
        }
        if ( !( ocifetchinto( $statement, $row, OCI_NUM ) ) )
        {
            eZDebug::writeNotice( "No data in file '$filePath'." );
            ocifreestatement( $statement );
            return false;
        }
        ocifreestatement( $statement );
        return $row[0];
    }


    /**
     * \private
     * \static
     */
    function _error( $statement, $sql )
    {
        $error = ocierror( $statement );
        eZDebug::writeError( "Failed query was: <$sql>", "Error executing query: " . $error['message'] );
    }

    /**
     * \private
     * \static
     */
    function _escapeString( $str )
    {
        return str_replace ("'", "''", $str );
    }

    var $db = null;
    var $dbparams = null;
}

?>