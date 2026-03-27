<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 * 
 * Copyright (c) 2020 Ronald M. Marasigan
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 4
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/**
* ------------------------------------------------------
*  Class Model / ORM
* ------------------------------------------------------
 */
class Model {  
    /**
     * Table Name of the Database
     *
     * @var string
     */
    protected $table = '';

    /**
     * Primary Key of the Database Column
     *
     * @var string
     */
    protected $primary_key = 'id';

    /**
     * Fillable attributes for Mass Assignment
     *
     * @var array
     */
    protected $fillable = [];

    /**
     * Guarded attributes that cannot be mass assigned
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * Automatically manage created_at and updated_at timestamps
     *
     * @var boolean
     */
    protected $timestamps = true;

    /**
     * Column name for created_at timestamp
     *
     * @var string
     */
    protected $created_at_column = 'created_at';

    /**
     * Column name for updated_at timestamp
     *
     * @var string
     */
    protected $updated_at_column = 'updated_at';

    /**
     * Column name to use for Soft Delete
     *
     * @var string
     */
    protected $soft_delete_column = 'deleted_at';

    /**
     * Allow Soft Delete of Rows (It will be added in config/config.php later on)
     *
     * @var boolean
     */
    protected $has_soft_delete;

    /**
     * Fillable attributes for Mass Assignment
     *
     * @var array
     */
    protected $with = [];

      /**
     * Class Constructor
     * @return void
     */
    public function __construct()
    {
        $this->has_soft_delete = $this->has_soft_delete ?? config_item('soft_delete');
        $this->soft_delete_column = $this->soft_delete_column ?? config_item('soft_delete_column');
        $this->created_at_column = $this->created_at_column ?? config_item('created_at_column');
        $this->updated_at_column = $this->updated_at_column ?? config_item('updated_at_column');
    }

    /**
     * Filter input data to only include fillable attributes
     * 
     * @param array $data Input data array
     * @param array $mergeFillable Additional fillable fields for this operation
     * @return array Filtered data array
     */
    protected function fillable_attributes(array $data, array $merge_fillable = []): array
    {
        $fillable = !empty($merge_fillable) 
            ? array_merge($this->fillable, $merge_fillable) 
            : $this->fillable;

        // Case 1: fillable is defined → use whitelist
        if (!empty($fillable)) {
            return array_intersect_key($data, array_flip($fillable));
        }

        // Case 2: guarded is defined → use blacklist (remove guarded fields)
        if (!empty($this->guarded)) {
            return array_diff_key($data, array_flip($this->guarded));
        }

        // Case 3: both empty → allow everything (legacy mode)
        return $data;
    }

    /**
     * Add timestamps to data array if enabled
     *
     * @param array $data Input data array
     * @param bool $is_update Whether this is an update operation (true) or create (false)
     * @return array Data array with timestamps added if enabled
     */
    protected function add_timestamps(array $data, bool $is_update = false): array
    {
        if (!$this->timestamps) {
            return $data;
        }

        $now = date('Y-m-d H:i:s');
        if (!$is_update) {
            $data[$this->created_at_column] = $now;
        }
        $data[$this->updated_at_column] = $now;

        return $data;
    }

    /**
     * Find Single Record
     *
     * @param integer $id
     * @param boolean $with_deleted
     * @return void
     */
    public function find($id, $with_deleted = false) {
        $this->db->table($this->table);
        $this->apply_soft_delete($with_deleted);
        return $this->db->where($this->primary_key, $id)->get();
    }

    /**
     * Row Count
     *
     * @param boolean $with_deleted
     * @return void
     */
    public function count($with_deleted = false) {
        $this->db->table($this->table);
        $this->apply_soft_delete($with_deleted);
        return $this->db->count();
    }

    /**
     * Find First Record
     *
     * @param boolean $with_deleted
     * @return void
     */
    public function first($with_deleted = false) {
        $this->db->table($this->table);
        $this->apply_soft_delete($with_deleted);
        return $this->db->order_by($this->primary_key, 'ASC')->limit(1)->get();
    }

    /**
     * Find Last Record
     *
     * @param boolean $with_deleted
     * @return void
     */
    public function last($with_deleted = false) {
        $this->db->table($this->table);
        $this->apply_soft_delete($with_deleted);
        return $this->db->order_by($this->primary_key, 'DESC')->limit(1)->get();
    }

    /**
     * Return all Rows from the Database
     *
     * @param boolean $with_deleted
     * @return void
     */
    public function all($with_deleted = false)
    {
        $this->db->table($this->table);
        $this->apply_soft_delete($with_deleted);
        $records = $this->db->get_all();

        if (!empty($this->with) && !empty($records)) {
            $this->load_relations($records);
        }

        $this->with = []; // reset after use
        return $records;
    }

    /**
     * Load related records for eager loading
     *
     * @param array $records
     * @return void
     */
    protected function load_relations(&$records)
    {
        foreach ($records as &$record) {
            foreach ($this->with as $relationName) {
                if (method_exists($this, $relationName)) {
                    $rel = $this->{$relationName}(); // calls e.g. posts() which returns config array

                    $this->call->model($rel['related']);

                    switch ($rel['type'] ?? '') {
                        case 'has_one':
                            $record[$relationName] = $this->{$rel['related']}
                                ->filter([$rel['foreign_key'] => $record[$this->primary_key]])
                                ->get();
                            break;

                        case 'has_many':
                            $record[$relationName] = $this->{$rel['related']}
                                ->filter([$rel['foreign_key'] => $record[$this->primary_key]])
                                ->get_all();
                            break;

                        case 'belongs_to':
                            $record[$relationName] = $this->{$rel['related']}
                                ->find($record[$rel['foreign_key']] ?? null);
                            break;

                        case 'many_to_many':
                            $query = "SELECT r.* FROM {$this->{$rel['related']}->table} r
                                      JOIN {$rel['pivot_table']} p 
                                      ON r.{$this->{$rel['related']}->primary_key} = p.{$rel['related_key']}
                                      WHERE p.{$rel['current_key']} = ?";
                            $record[$relationName] = $this->db->raw($query, [$record[$this->primary_key]])
                                                              ->fetchAll(PDO::FETCH_ASSOC);
                            break;
                    }
                }
            }
        }
    }

    /**
     * Eager Load Relationships
     *
     * @param mixed $relations
     * @return $this
     */
    public function with($relations)
    {
        $this->with = is_array($relations) ? $relations : [$relations];
        return $this;
    }


    /**
     * Restore Soft Deleted Row
     *
     * @param int $id
     * @return void
     */
    public function restore($id)
    {
        if (!$this->has_soft_delete) return false;

        return $this->db->table($this->table)
                        ->where($this->primary_key, $id)
                        ->update([$this->soft_delete_column => null]);
    }

    /**
     * Check if record exists
     *
     * @param mixed $id_or_conditions
     * @param bool $with_deleted
     * @return bool
     */
    public function exists($conditions = [], $with_deleted = false)
    {
        $this->db->table($this->table);
        $this->apply_soft_delete($with_deleted);
        return !empty($this->db->where($conditions)->get_all());
    }

    /**
     * Paginate results
     *
     * @param int $per_page
     * @param int $page
     * @param array $conditions
     * @param bool $with_deleted
     * @return array
     */
    public function paginate($per_page = 10, $page = 1, $conditions = [], $with_deleted = false)
    {
        $offset = ($page - 1) * $per_page;
        $this->db->table($this->table);
        $this->apply_soft_delete($with_deleted);
        
        if (!empty($conditions)) {
            $this->db->where($conditions);
        }
        
        $total = $this->db->count();
        $results = $this->db->table($this->table)->limit($per_page, $offset)->get_all();
        
        return [
            'data' => $results,
            'total' => $total,
            'per_page' => $per_page,
            'current_page' => $page,
            'last_page' => ceil($total / $per_page)
        ];
    }

    /**
     * Empty Table
     *
     * @return void
     */
    public function truncate() {
        return $this->db->table($this->table)->delete();
    }

    /**
     * Order By
     *
     * @param string $column
     * @param string $order
     * @return void
     */
    public function order_by($column, $order = 'ASC', $with_deleted = false) {
        $this->db->table($this->table);
        $this->apply_soft_delete($with_deleted);
        return $this->db->order_by($column, $order)->get_all();
    }

    /**
     * Limit
     *
     * @param int $number
     * @return void
     */
    public function limit($number, $with_deleted = false) {
        $this->db->table($this->table);
        $this->apply_soft_delete($with_deleted);
        return $this->db->limit($number)->get_all();
    }
    
    /**
     * Get Table Columns
     *
     * @return void
     */
    public function get_columns() {
        $stmt = $this->db->raw("SHOW COLUMNS FROM {$this->table}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Bulk Insert
     *
     * @param array $data
     * @return void
     */
    public function bulk_insert($data)
    {
        if (empty($data)) {
            return false;
        }

        foreach ($data as &$row) {
            $row = $this->fillable_attributes($row);
            if (!empty($row) && $this->timestamps) {
                $row = $this->add_timestamps($row);
            }
        }

        return $this->db->table($this->table)->bulk_insert(array_filter($data));
    }

    /**
     * Bulk Update
     *
     * @param array $data
     * @param int $key
     * @return void
     */
    public function bulk_update($data, $key = '', $merge_fillable = [])
    {
        if (empty($data)) {
            return false;
        }

        $key = $key ?: $this->primary_key;
        $updatedCount = 0;

        foreach ($data as $row) {
            if (empty($row[$key])) {
                continue;
            }

            $id = $row[$key];
            unset($row[$key]);

            $updateData = $this->fillable_attributes($row, $merge_fillable);
            if (empty($updateData)) {
                continue;
            }

            $updateData = $this->add_timestamps($updateData, true);

            $result = $this->db->table($this->table)
                               ->where($key, $id)
                               ->update($updateData);

            if ($result) {
                $updatedCount++;
            }
        }

        return $updatedCount;
    }

    /**
     * Insert Record to the Database
     *
     * @param array $data
     * @return void
     */
    public function insert($data)
    {
        $data = $this->fillable_attributes($data);
        if (empty($data)) {
            return false;
        }

        $data = $this->add_timestamps($data);
        $this->db->table($this->table)->insert($data);
        return $this->db->last_id();
    }

    /**
     * Update Record from the Database
     *
     * @param integer $id
     * @param array $data
     * @return void
     */
    public function update($id, $data)
    {
        $data = $this->fillable_attributes($data);
        if (empty($data)) {
            return false;
        }

        $data = $this->add_timestamps($data, true);
        return $this->db->table($this->table)
                        ->where($this->primary_key, $id)
                        ->update($data);
    }

    /**
     * Soft Delete. Check the column name to be added in the table. Check protected $soft_delete_column = 'deleted_at';
     *
     * @param integer $id
     * @return void
     */
    public function soft_delete($id)
    {
        if (!$this->has_soft_delete) {
            return $this->delete($id);
        }

        return $this->db->table($this->table)
                        ->where($this->primary_key, $id)
                        ->update([$this->soft_delete_column => date('Y-m-d H:i:s')]);
    }

    /**
     * Delete Records from the Database
     *
     * @param integer $id
     * @return void
     */
    public function delete($id)
    {
        return $this->db->table($this->table)
                        ->where($this->primary_key, $id)
                        ->delete();
    }

    /**
     * Filter using where Clause
     *
     * @param array $conditions
     * @param boolean $with_deleted
     * @return void
     */
    public function filter($conditions = [], $with_deleted = false) {
        $this->db->table($this->table);
        $this->apply_soft_delete($with_deleted);
        return $this->db->where($conditions);
    }

    /**
     * Group by clause
     *
     * @param string $column
     * @return $this
     */
    public function group_by($column)
    {
        $this->db->table($this->table);
        $this->db->group_by($column);
        return $this;
    }

    /**
     * Having clause
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @return $this
     */
    public function having($column, $operator, $value)
    {
        $this->db->table($this->table);
        $this->db->having($column, $operator, $value);
        return $this;
    }

    /**
     * Raw SQL Query
     *
     * @param string $sql
     * @param array $params
     * @return void
     */
    public function raw($sql, $params = []) {
        return $this->db->raw($sql, $params);
    }
    /**
     * Apply Soft Delete when Displaying Records
     *
     * @param boolean $with_deleted
     * @return void
     */
    protected function apply_soft_delete(bool $with_deleted): void
    {
        if (!$with_deleted && $this->has_soft_delete) {
            $this->db->where_null($this->soft_delete_column);
        }
    }

    /**
     * ORM Has Many Relationship
     *
     * @param string $related_model
     * @param string $foreign_key
     * @param mixed $current_id
     * @return boolean
     */
    protected function has_many(string $related, string $foreign_key): array
    {
        return ['type' => 'has_many', 'related' => $related, 'foreign_key' => $foreign_key];
    }

    /**
     * ORM Has One Relationship
     *
     * @param string $related_model
     * @param string $foreign_key
     * @param mixed $current_id
     * @return boolean
     */
    protected function has_one(string $related, string $foreign_key): array
    {
        return ['type' => 'has_one', 'related' => $related, 'foreign_key' => $foreign_key];
    }

    /**
     * Many To Many Relationship
     *
     * @param string $related_model
     * @param string $pivot_table
     * @param string $current_key
     * @param string $related_key
     * @param mixed $current_id
     * @return void
     */
    protected function many_to_many(string $related, string $pivot_table, string $current_key, string $related_key): array
    {
        return [
            'type'         => 'many_to_many',
            'related'      => $related,
            'pivot_table'  => $pivot_table,
            'current_key'  => $current_key,
            'related_key'  => $related_key
        ];
    }

    /**
     * Belong To Relationship
     *
     * @param string $related_model
     * @param string $foreign_key
     * @param mixed $foreign_key_value
     * @return void
     */
    protected function belongs_to(string $related, string $foreign_key): array
    {
        return ['type' => 'belongs_to', 'related' => $related, 'foreign_key' => $foreign_key];
    }
    
    /**
     * Scope query
     *
     * @param callable $callback
     * @return $this
     */
    public function scope($callback)
    {
        call_user_func($callback, $this->db);
        return $this;
    }

    /**
     * Begin Transaction
     *
     * @return void
     */
    public function transaction()
    {
        $this->db->transaction();
    }

    /**
     * Commit
     *
     * @return void
     */
    public function commit()
    {
        $this->db->commit();
    }

    /**
     * Rollback
     *
     * @return void
     */
    public function rollback()
    {
        $this->db->rollback();
    }

     /**
     * magic __get
     *
     * @param string $key
     * @return void
     */
    public function __get($key)
    {
        return lava_instance()->$key;
    }
}