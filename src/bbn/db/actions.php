<?php
/**
 * @package db
 */
namespace bbn\db;
/**
 * DB Interface
 *
 *
 * These methods have to be implemented on both database and query.
 * Most methods usable on query should be also usable directly through database, which will create the query apply its method.
 *
 * @author Thomas Nabet <thomas.nabet@gmail.com>
 * @copyright BBN Solutions
 * @since Apr 4, 2011, 23:23:55 +0000
 * @category  Database
 * @license   http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @version 0.2r89
 */
interface actions
{

	/**
	 * Fetches the database and returns an array of a single row text-indexed
	 *
	 * @params 
	 * @return false|array
	 */
	public function get_row();

	/**
	 * Fetches the database and returns an array of several arrays of rows text-indexed
	 *
	 * @return false|array
	 */
	public function get_rows();

	/**
	 * Fetches the database and returns an array of a single row num-indexed
	 *
	 * @return false|array
	 */
	public function get_irow();

	/**
	 * Fetches the database and returns an array of several arrays of rows num-indexed
	 *
	 * @return false|array
	 */
	public function get_irows();

	/**
	 * Fetches the database and returns an array of arrays, one per column, each having each column's values
	 *
	 * @return false|array
	 */
	public function get_by_columns();

	/**
	 * Fetches the database and returns an object of a single row, alias of get_object
	 *
	 * @return false|object
	 */
	public function get_obj();

	/**
	 * Fetches the database and returns an object of a single row
	 *
	 * @return false|object
	 */
	public function get_object();

	/**
	 * Fetches the database and returns an array of objects 
	 *
	 * @return false|array
	 */
	public function get_objects();
}
