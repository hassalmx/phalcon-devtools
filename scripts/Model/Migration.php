<?php

/*
  +------------------------------------------------------------------------+
  | Phalcon Framework                                                      |
  +------------------------------------------------------------------------+
  | Copyright (c) 2011-2012 Phalcon Team (http://www.phalconphp.com)       |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file docs/LICENSE.txt.                        |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconphp.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Authors: Andres Gutierrez <andres@phalconphp.com>                      |
  |          Eduar Carvajal <eduar@phalconphp.com>                         |
  +------------------------------------------------------------------------+
*/

/**
 * Phalcon_Model_Migration
 *
 * Migrations of DML y DDL over databases
 *
 * @category 	Phalcon
 * @package 	Scripts
 * @copyright   Copyright (c) 2011-2012 Phalcon Team (team@phalconphp.com)
 * @license 	New BSD License
 */
class Phalcon_Model_Migration {

	/**
	 * Migration database connection
	 *
	 * @var DbBase
	 */
	private static $_connection;

	/**
	 * Path where to save the migration
	 *
	 * @var string
	 */
	private static $_migrationPath = null;

	/**
	 * Prepares component
	 *
	 * @param stdClass $config
	 */
	public static function setup($config){
		Phalcon_Db_Pool::setDefaultDescriptor($config);
		self::$_connection = Phalcon_Db_Pool::getConnection();
		self::$_connection->setProfiler(new Phalcon_Model_Migration_Profiler());
	}

	/**
	 * Set the migration directory path
	 *
	 * @param string $path
	 */
	public static function setMigrationPath($path){
		self::$_migrationPath = $path;
	}

	/**
	 * Generates all the class migration definitions for certain database setup
	 *
	 * @param	string $version
	 * @param	string $exportData
	 * @return	string
	 */
	public static function generateAll($version, $exportData=null){
		$classDefinition = array();
		foreach(self::$_connection->listTables() as $table){
			$classDefinition[$table] = self::generate($version, $table, $exportData);
		}
		return $classDefinition;
	}

	/**
	 * Generate specified table migration
	 *
	 * @param	string $version
	 * @param	string $table
	 * @param 	string $exportData
	 * @return	string
	 */
	public static function generate($version, $table, $exportData=null){

		$oldColumn = null;
		$allFields = array();
		$numericFields = array();
		$tableDefinition = array();
		$defaultSchema = self::$_connection->getDefaultSchema();
		$description = self::$_connection->describeTable($table, $defaultSchema);
		foreach($description as $field){
			$fieldDefinition = array();
			if(preg_match('/([a-z]+)(\(([0-9]+)(,([0-9]+))*\)){0,1}/', $field['Type'], $matches)){
				switch($matches[1]){
					case 'int':
					case 'smallint':
					case 'double':
						$fieldDefinition[] = "'type' => Column::TYPE_INTEGER";
						$numericFields[$field['Field']] = true;
						break;
					case 'varchar':
						$fieldDefinition[] = "'type' => Column::TYPE_VARCHAR";
						break;
					case 'char':
						$fieldDefinition[] = "'type' => Column::TYPE_CHAR";
						break;
					case 'date':
						$fieldDefinition[] = "'type' => Column::TYPE_DATE";
						break;
					case 'datetime':
						$fieldDefinition[] = "'type' => Column::TYPE_DATETIME";
						break;
                    case 'float':
    					$fieldDefinition[] = "'type' => Column::TYPE_DECIMAL";
						$numericFields[$field['Field']] = true;
						break;
					case 'decimal':
						$fieldDefinition[] = "'type' => Column::TYPE_DECIMAL";
						$numericFields[$field['Field']] = true;
						break;
					case 'text':
						$fieldDefinition[] = "'type' => Column::TYPE_TEXT";
						break;
					case 'enum':
						$fieldDefinition[] = "'type' => Column::TYPE_CHAR";
						$fieldDefinition[] = "'size' => 1";
						break;
					default:
						throw new Phalcon_Model_Exception('Unrecognized data type '.$matches[1].' at column '.$field['Field']);
				}
				if(isset($matches[3])){
					$fieldDefinition[] = "'size' => ".$matches[3];
				}
				if(isset($matches[5])){
					$fieldDefinition[] = "'scale' => ".$matches[5];
				}
				if(strpos($field['Type'], 'unsigned')){
					$fieldDefinition[] = "'unsigned' => true";
				}
			} else {
				throw new Phalcon_Model_Exception('Unrecognized data type '.$field['Type']);
			}
			if($field['Key']=='PRI'){
				$fieldDefinition[] = "'primary' => true";
			}
			if($field['Null']=='NO'){
				$fieldDefinition[] = "'notNull' => true";
			}
			if($field['Extra']=='auto_increment'){
				$fieldDefinition[] = "'autoIncrement' => true";
			}
			if($oldColumn!=null){
				$fieldDefinition[] = "'after' => '".$oldColumn."'";
			} else {
				$fieldDefinition[] = "'first' => true";
			}
			$oldColumn = $field['Field'];
			$tableDefinition[] = "\t\t\t\tnew Column('".$field['Field']."', array(\n\t\t\t\t\t".join(",\n\t\t\t\t\t", $fieldDefinition)."\n\t\t\t\t))";
			$allFields[] = "'".$field['Field']."'";
		}

		$indexesDefinition = array();
		$indexes = self::$_connection->describeIndexes($table, $defaultSchema);
		foreach($indexes as $indexName => $dbIndex){
			$indexDefinition = array();
			foreach($dbIndex->getColumns() as $indexColumn){
				$indexDefinition[] = "'".$indexColumn."'";
			}
			$indexesDefinition[] = "\t\t\t\tnew Index('".$indexName."', array(\n\t\t\t\t\t".join(",\n\t\t\t\t\t", $indexDefinition)."\n\t\t\t\t))";
		}

		$referencesDefinition = array();
		$references = self::$_connection->describeReferences($table, $defaultSchema);
		foreach($references as $constraintName => $dbReference){

			$columns = array();
			foreach($dbReference->getColumns() as $column){
				$columns[] = "'".$column."'";
			}

			$referencedColumns = array();
			foreach($dbReference->getReferencedColumns() as $referencedColumn){
				$referencedColumns[] = "'".$referencedColumn."'";
			}

			$referenceDefinition = array();
			$referenceDefinition[] = "'referencedSchema' => '".$dbReference->getReferencedSchema()."'";
			$referenceDefinition[] = "'referencedTable' => '".$dbReference->getReferencedTable()."'";
			$referenceDefinition[] = "'columns' => array(".join(",", $columns).")";
			$referenceDefinition[] = "'referencedColumns' => array(".join(",", $referencedColumns).")";

			$referencesDefinition[] = "\t\t\t\tnew Reference('".$constraintName."', array(\n\t\t\t\t\t".join(",\n\t\t\t\t\t", $referenceDefinition)."\n\t\t\t\t))";
		}

		$optionsDefinition = array();
		$tableOptions = self::$_connection->tableOptions($table, $defaultSchema);
		foreach($tableOptions as $optionName => $optionValue){
			$optionsDefinition[] = "\t\t\t\t'$optionName' => '".$optionValue."'";
		}

		$classVersion = preg_replace('/[^0-9A-Za-z]/', '', $version);
		$className = Phalcon_Utils::camelize($table).'Migration_'.$classVersion;
		$classData = "use Phalcon_Db_Column as Column;
use Phalcon_Db_Index as Index;
use Phalcon_Db_Reference as Reference;

class ".$className." extends Phalcon_Model_Migration {\n\n".
		"\tpublic function up(){\n\t\t\$this->morphTable('".$table."', array(".
		"\n\t\t\t'columns' => array(\n".join(",\n", $tableDefinition)."\n\t\t\t),";
		if(count($indexesDefinition)){
			$classData.="\n\t\t\t'indexes' => array(\n".join(",\n", $indexesDefinition)."\n\t\t\t),";
		}
		if(count($referencesDefinition)){
			$classData.="\n\t\t\t'references' => array(\n".join(",\n", $referencesDefinition)."\n\t\t\t),";
		}
		if(count($optionsDefinition)){
			$classData.="\n\t\t\t'options' => array(\n".join(",\n", $optionsDefinition)."\n\t\t\t)\n";
		}
		$classData.="\t\t));\n\t}";
		if($exportData=='always'||$exportData=='oncreate'){
			if($exportData=='oncreate'){
				$classData.="\n\n\tpublic function afterCreateTable(){\n";
			} else {
				$classData.="\n\n\tpublic function afterUp(){\n";
			}
			$classData.="\t\t\$this->batchInsert('$table', array(\n\t\t\t".join(",\n\t\t\t", $allFields)."\n\t\t));";

			$fileHandler = fopen(self::$_migrationPath.'/'.$table.'.dat', 'w');
			$cursor = self::$_connection->query('SELECT * FROM '.$table);
			$cursor->setFetchMode(Phalcon_Db::DB_ASSOC);
			while($row = $cursor->fetchArray($cursor)){
				$data = array();
				foreach($row as $key => $value){
					if(isset($numericFields[$key])){
						if($value===''||is_null($value)){
							$data[] = 'NULL';
						} else {
							$data[] = addslashes($value);
						}
					} else {
						$data[] = "'".addslashes($value)."'";
					}
					unset($value);
				}
				fputs($fileHandler, join('|', $data).PHP_EOL);
				unset($row);
				unset($data);
			}
			fclose($fileHandler);

			$classData.="\n\t}";
		}
		$classData.="\n\n}";
		$classData = str_replace("\t", "    ", $classData);
		return $classData;
	}

	/**
	 * Migrate single file
	 *
	 * @param string $version
	 * @param string $filePath
	 */
	public static function migrateFile($version, $filePath){
		if(file_exists($filePath)){
			$fileName = basename($filePath);
			$classVersion = preg_replace('/[^0-9A-Za-z]/', '', $version);
			$className = Phalcon_Utils::camelize(str_replace('.php', '', $fileName)).'Migration_'.$classVersion;
			require $filePath;
			if(class_exists($className)){
				$migration = new $className();
				if(method_exists($migration, 'up')){
					$migration->up();
					if(method_exists($migration, 'afterUp')){
						$migration->afterUp();
					}
				}
			} else {
				throw new Phalcon_Model_Exception('Migration class cannot be found '.$className.' at '.$filePath);
			}
		}
	}

	/**
	 * Look for table definition modifications and apply to real table
	 *
	 * @param string $tableName
	 * @param array $tableColumns
	 */
	public function morphTable($tableName, $definition){

		$defaultSchema = self::$_connection->getDefaultSchema();
		$tableExists = self::$_connection->tableExists($tableName, $defaultSchema);
		if(isset($definition['columns'])){
			if(count($definition['columns'])==0){
				throw new Phalcon_Model_Exception('Table must have at least one column');
			}
			$fields = array();
			foreach($definition['columns'] as $tableColumn){
				if(!is_object($tableColumn)){
					throw new Phalcon_Model_Exception('Table must have at least one column');
				}
				$fields[$tableColumn->getName()] = $tableColumn;
			}
			if($tableExists==true){
				$localFields = array();
				$description = self::$_connection->describeTable($tableName, $defaultSchema);
				foreach($description as $field){
					$localFields[$field['Field']] = $field;
				}
				foreach($fields as $fieldName => $tableColumn){
					if(!isset($localFields[$fieldName])){
						self::$_connection->addColumn($tableName, $tableColumn->getSchemaName(), $tableColumn);
					} else {
						$changed = false;
						$columnDefinition = strtolower(self::$_connection->getColumnDefinition($tableColumn));
						if($localFields[$fieldName]['Type']!=$columnDefinition){
							$changed = true;
						}
						if($tableColumn->isNotNull()!=true && $localFields[$fieldName]['Null']=='NO'){
							$changed = true;
						} else {
							if($tableColumn->isNotNull()==true && $localFields[$fieldName]['Null']=='YES'){
								$changed = true;
							}
						}
						if($changed==true){
							self::$_connection->modifyColumn($tableName, $tableColumn->getSchemaName(), $tableColumn);
						}
					}
				}
				foreach($localFields as $fieldName => $localField){
					if(!isset($fields[$fieldName])){
						self::$_connection->dropColumn($tableName, null, $fieldName);
					}
				}
			} else {
				self::$_connection->createTable($tableName, $defaultSchema, $definition);
				if(method_exists($this, 'afterCreateTable')){
					$this->afterCreateTable();
				}
			}
		}

		if(isset($definition['references'])){
			if($tableExists==true){
				$references = array();
				foreach($definition['references'] as $tableReference){
					$references[$tableReference->getName()] = $tableReference;
				}
				$localReferences = array();
				$activeReferences = self::$_connection->describeReferences($tableName, $defaultSchema);
				foreach($activeReferences as $activeReference){
					$localReferences[$activeReference->getName()] = array(
						'referencedTable' => $activeReference->getReferencedTable(),
						'columns' => $activeReference->getColumns(),
						'referencedColumns' => $activeReference->getReferencedColumns(),
					);
				}
				foreach($definition['references'] as $tableReference){
					if(!isset($localReferences[$tableReference->getName()])){
						self::$_connection->addForeignKey($tableName, $tableReference->getSchemaName(), $tableReference);
					} else {
						$changed = false;
						if($tableReference->getReferencedTable()!=$localReferences[$tableReference->getName()]['referencedTable']){
							$changed = true;
						}
						if($changed==false){
							if(count($tableReference->getColumns())!=count($localReferences[$tableReference->getName()]['columns'])){
								$changed = true;
							}
						}
						if($changed==false){
							if(count($tableReference->getReferencedColumns())!=count($localReferences[$tableReference->getName()]['referencedColumns'])){
								$changed = true;
							}
						}
						if($changed==false){
							foreach($tableReference->getColumns() as $columnName){
								if(!in_array($columnName, $localReferences[$tableReference->getName()]['columns'])){
									$changed = true;
									break;
								}
							}
						}
						if($changed==false){
							foreach($tableReference->getReferencedColumns() as $columnName){
								if(!in_array($columnName, $localReferences[$tableReference->getName()]['referencedColumns'])){
									$changed = true;
									break;
								}
							}
						}
						if($changed==true){
							self::$_connection->dropForeignKey($tableName, $tableReference->getSchemaName(), $tableReference->getName());
							self::$_connection->addForeignKey($tableName, $tableReference->getSchemaName(), $tableReference);
						}
					}
				}
				foreach($localReferences as $referenceName => $reference){
					if(!isset($references[$referenceName])){
						self::$_connection->dropForeignKey($tableName, null, $referenceName);
					}
				}
			}
		}

		if(isset($definition['indexes'])){
			if($tableExists==true){
				$indexes = array();
				foreach($definition['indexes'] as $tableIndex){
					$indexes[$tableIndex->getName()] = $tableIndex;
				}
				$localIndexes = array();
				$actualIndexes = self::$_connection->describeIndexes($tableName, $defaultSchema);
				foreach($actualIndexes as $actualIndex){
					$localIndexes[$actualIndex->getName()] = $actualIndex->getColumns();
				}
				foreach($definition['indexes'] as $tableIndex){
					if(!isset($localIndexes[$tableIndex->getName()])){
						if($tableIndex->getName()=='PRIMARY'){
							self::$_connection->addPrimaryKey($tableName, $tableColumn->getSchemaName(), $tableIndex);
						} else {
							self::$_connection->addIndex($tableName, $tableColumn->getSchemaName(), $tableIndex);
						}
					} else {
						$changed = false;
						if(count($tableIndex->getColumns())!=count($localIndexes[$tableIndex->getName()])){
							$changed = true;
						} else {
							foreach($tableIndex->getColumns() as $columnName){
								if(!in_array($columnName, $localIndexes[$tableIndex->getName()])){
									$changed = true;
									break;
								}
							}
						}
						if($changed==true){
							if($tableIndex->getName()=='PRIMARY'){
								self::$_connection->dropPrimaryKey($tableName, $tableColumn->getSchemaName());
								self::$_connection->addPrimaryKey($tableName, $tableColumn->getSchemaName(), $tableIndex);
							} else {
								self::$_connection->dropIndex($tableName, $tableColumn->getSchemaName(), $tableIndex->getName());
								self::$_connection->addIndex($tableName, $tableColumn->getSchemaName(), $tableIndex);
							}
						}
					}
				}
				foreach($localIndexes as $indexName => $indexColumns){
					if(!isset($indexes[$indexName])){
						self::$_connection->dropIndex($tableName, null, $indexName);
					}
				}
			}
		}

	}

	/**
	 * Inserts data from a data migration file in a table
	 *
	 * @param string $tableName
	 * @param string $fields
	 */
	public function batchInsert($tableName, $fields){
		$migrationData = self::$_migrationPath.'/'.$tableName.'.dat';
		if(file_exists($migrationData)){
			self::$_connection->begin();
			self::$_connection->delete($tableName);
			$batchHandler = fopen($migrationData, 'r');
			while(($line = fgets($batchHandler))!==false){
				self::$_connection->insert($tableName, explode('|', rtrim($line)), $fields, false);
				unset($line);
			}
			fclose($batchHandler);
			self::$_connection->commit();
		}
	}

}
