<?php
/*
* This file is part of prooph/link.
 * (c) prooph software GmbH <contact@prooph.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 * Date: 1/26/15 - 7:35 PM
 */
namespace Prooph\Link\SqlConnector\Service;

use Prooph\Link\Application\SharedKernel\ApplicationDataTypeLocation;
use Prooph\Link\Application\SharedKernel\ConfigLocation;
use Assert\Assertion;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Prooph\Processing\Message\MessageNameUtils;
use Prooph\ServiceBus\CommandBus;
use Prooph\Link\Application\Command\AddConnectorToConfig;
use Prooph\Link\Application\Command\ChangeConnectorConfig;

/**
 * Class TableConnectorGenerator
 *
 * Takes a sql table connector definition,
 * generates table row processing types with the help of the doctrine dbal schema manager
 * and stores the definition in the processing config.
 *
 * @package SqlConnector\Service
 * @author Alexander Miertsch <kontakt@codeliner.ws>
 */
final class TableConnectorGenerator 
{
    const TAB = "    ";
    const ICON = "glyphicon-hdd";
    const METADATA_UI_KEY = "sqlconnector-metadata";

    /**
     * @var CommandBus
     */
    private $commandBus;

    /**
     * @var ApplicationDataTypeLocation
     */
    private $dataTypeLocation;

    /**
     * @var ConfigLocation
     */
    private $configLocation;

    /**
     * @var DbalConnectionCollection
     */
    private $dbalConnections;

    /**
     * @var array
     */
    private $doctrineProcessingTypeMap;

    /**
     * @param DbalConnectionCollection $dbalConnections
     * @param ApplicationDataTypeLocation $dataTypeLocation
     * @param ConfigLocation $configLocation
     * @param CommandBus $commandBus
     * @param array $doctrineProcessingTypeMap
     */
    public function __construct(
        DbalConnectionCollection $dbalConnections,
        ApplicationDataTypeLocation $dataTypeLocation,
        ConfigLocation $configLocation,
        CommandBus $commandBus,
        array $doctrineProcessingTypeMap
    ) {
        $this->dbalConnections = $dbalConnections;
        $this->dataTypeLocation = $dataTypeLocation;
        $this->configLocation = $configLocation;
        $this->commandBus = $commandBus;
        $this->doctrineProcessingTypeMap = $doctrineProcessingTypeMap;
    }

    /**
     * @param string $id
     * @param array $connector
     * @throws \InvalidArgumentException
     */
    public function addConnector($id, array $connector)
    {
        $this->assertConnector($connector);

        if (! $this->dbalConnections->containsKey($connector['dbal_connection'])) {
            throw new \InvalidArgumentException(sprintf("Dbal connection %s for connector %s does not exists", $connector['dbal_connection'], $connector['name']));
        }

        $connection = $this->dbalConnections->get($connector['dbal_connection']);

        $generatedTypes = $this->generateProcessingTypesIfNotExist($connection->config()['dbname'], $connector['table'], $connection->connection());

        $connectorName = $connector['name'];

        unset($connector['name']);

        $connector['icon'] = self::ICON;
        $connector['ui_metadata_riot_tag'] = self::METADATA_UI_KEY;

        $addConnector = AddConnectorToConfig::fromDefinition(
            $id,
            $connectorName,
            [
                MessageNameUtils::COLLECT_DATA,
                MessageNameUtils::PROCESS_DATA
            ],
            $generatedTypes,
            $this->configLocation,
            $connector
        );

        $this->commandBus->dispatch($addConnector);
    }

    public function updateConnector($id, array $connector, $regenerateType = false)
    {
        $this->assertConnector($connector);

        if (! $this->dbalConnections->containsKey($connector['dbal_connection'])) {
            throw new \InvalidArgumentException(sprintf("Dbal connection %s for connector %s does not exists", $connector['dbal_connection'], $connector['name']));
        }

        $connection = $this->dbalConnections->get($connector['dbal_connection']);

        if ($regenerateType) {
            $generatedTypes = $this->replaceProcessingTypes($connection->config()['dbname'], $connector['table'], $connection->connection());
        } else {
            $generatedTypes = $this->generateTypeClassFQCNs($connection->config()['dbname'], $connector['table']);
        }

        $connector['icon'] = self::ICON;
        $connector['ui_metadata_riot_tag'] = self::METADATA_UI_KEY;
        $connector['allowed_types'] = $generatedTypes;
        $connector['allowed_messages'] = [
            MessageNameUtils::COLLECT_DATA,
            MessageNameUtils::PROCESS_DATA
        ];

        $command = ChangeConnectorConfig::ofConnector($id, $connector, $this->configLocation);

        $this->commandBus->dispatch($command);
    }

    /**
     * @param array $connector
     */
    private function assertConnector(array $connector)
    {
        Assertion::keyExists($connector, "dbal_connection");
        Assertion::keyExists($connector, "table");
        Assertion::keyExists($connector, "name");
    }

    /**
     * Generate type classes for given table. For each table a type is generated representing a single row and
     * a collection type for the row type.
     * The type class is named after the table
     * but the name is "titleized": _ and - are stripped and words start with a capital letter.
     *
     * @param string $dbname
     * @param string $table
     * @param Connection $connection
     * @return array
     */
    private function generateProcessingTypesIfNotExist($dbname, $table, Connection $connection)
    {
        $dbNsName = $this->titleize($dbname);
        $rowClassName = $this->titleize($table);
        $collectionClassName = $rowClassName . "Collection";
        $namespace = 'Prooph\Link\Application\DataType\SqlConnector\\' . $dbNsName;

        $this->dataTypeLocation->addDataTypeClass(
            $namespace . '\\' . $rowClassName,
            $this->generateRowTypeClass($namespace, $rowClassName, $table, $connection)
        );

        $this->dataTypeLocation->addDataTypeClass(
            $namespace . '\\' . $collectionClassName,
            $this->generateRowCollectionTypeClass($namespace, $collectionClassName, $rowClassName)
        );

        return [
            $namespace . '\\' . $rowClassName,
            $namespace . '\\' . $collectionClassName
        ];
    }

    /**
     * Does the same like generateProcessingTypesIfNotExist but overrides any existing type classes.
     * Use this method with care.
     *
     * @param string $dbname
     * @param string $table
     * @param Connection $connection
     * @return array
     */
    private function replaceProcessingTypes($dbname, $table, Connection $connection)
    {
        $dbNsName = $this->titleize($dbname);
        $rowClassName = $this->titleize($table);
        $collectionClassName = $rowClassName . "Collection";
        $namespace = 'Prooph\Link\Application\DataType\SqlConnector\\' . $dbNsName;

        $this->dataTypeLocation->addDataTypeClass(
            $namespace . '\\' . $rowClassName,
            $this->generateRowTypeClass($namespace, $rowClassName, $table, $connection),
            $forceReplace = true
        );

        $this->dataTypeLocation->addDataTypeClass(
            $namespace . '\\' . $collectionClassName,
            $this->generateRowCollectionTypeClass($namespace, $collectionClassName, $rowClassName),
            $forceReplace = true
        );

        return [
            $namespace . '\\' . $rowClassName,
            $namespace . '\\' . $collectionClassName
        ];
    }

    private function generateTypeClassFQCNs($dbname, $table)
    {
        $dbNsName = $this->titleize($dbname);
        $rowClassName = $this->titleize($table);
        $collectionClassName = $rowClassName . "Collection";
        $namespace = 'Prooph\Link\Application\DataType\SqlConnector\\' . $dbNsName;

        return [
            $namespace . '\\' . $rowClassName,
            $namespace . '\\' . $collectionClassName
        ];
    }

    /**
     * Inspect given table and creates a map containing the mapped processing type and the original doctrine type
     * The map is indexed by column names which become the property names
     *
     * @param string $table
     * @param Connection $connection
     * @return array
     */
    private function loadPropertiesForTable($table, Connection $connection)
    {
        $columns = $connection->getSchemaManager()->listTableColumns($table);

        $props = [];

        foreach ($columns as $name => $column)
        {
            $processingType = $this->doctrineColumnToProcessingType($column);

            $props[$name] = [
                'processing_type' => $processingType,
                'doctrine_type' => $column->getType()->getName(),
            ];
        }

        return $props;
    }

    /**
     * If table has a primary key this becomes the identifier of the row type.
     * If primary key consists of more then one column, the name of the identifier is generated by
     * concatenating the column names with a underscore.
     * Such a row type needs to be manually adjusted to deal with the identifier!
     *
     * @param $table
     * @param Connection $connection
     * @return null|string
     */
    private function loadPrimaryKeyforTable($table, Connection $connection)
    {
        $indexes = $connection->getSchemaManager()->listTableIndexes($table);

        $primaryKey = null;

        foreach ($indexes as $index) {
            if ($index->isPrimary()) {
                $columns = $index->getColumns();

                $primaryKey = implode("_", $columns);
                break;
            }
        }

        return $primaryKey;
    }

    /**
     * @param Column $column
     * @return string
     * @throws \RuntimeException
     */
    private function doctrineColumnToProcessingType(Column $column)
    {
        if (! isset($this->doctrineProcessingTypeMap[$column->getType()->getName()])) {
            throw new \RuntimeException(sprintf("No processing type mapping for doctrine type %s", $column->getType()->getName()));
        }

        $processingType = $this->doctrineProcessingTypeMap[$column->getType()->getName()];

        if (! $column->getNotnull() || $column->getAutoincrement()) {
            $processingType.= "OrNull";

            if (! class_exists($processingType)) {
                throw new \RuntimeException(
                    "Missing null type: for nullable column: " . $column->getName()
                );
            }
        }

        Assertion::implementsInterface($processingType, 'Prooph\Processing\Type\Type');

        return $processingType;
    }

    private function propertiesToProcessingPropString(array $properties)
    {
        $propString = "[\n";

        foreach ($properties as $name => $propDef) {
            $propString.= self::TAB . self::TAB . self::TAB . "'" .$name . "' => \\" . $propDef['processing_type'] . "::prototype(),\n";
        }

        $propString.= "\n" . self::TAB . self::TAB ."]";

        return $propString;
    }

    private function propertiesToDoctrineTypeString(array $properties)
    {
        $propString = "[\n";

        foreach ($properties as $name => $propDef) {
            $propString.= self::TAB . self::TAB . "'" .$name . "' => '" . $propDef['doctrine_type'] . "',\n";
        }

        $propString.= "\n" . self::TAB . "]";

        return $propString;
    }

    private function titleize($dbName)
    {
        $replace = ["_", "-"];
        $with = " ";
        return str_replace(" ", "", ucwords(str_replace($replace, $with, $dbName)));
    }

    /**
     * @param string $namespace
     * @param string $className
     * @param string $table
     * @param Connection $connection
     * @return string generated class
     */
    private function generateRowTypeClass($namespace, $className, $table, Connection $connection)
    {
        $properties = $this->loadPropertiesForTable($table, $connection);
        $primaryKey = $this->loadPrimaryKeyforTable($table, $connection);
        $hasPrimaryKey = ($primaryKey)? "true" : "false";
        $propertyNames = array_keys($properties);

        //Try to match primary key by performing case insensitive compare
        if ($hasPrimaryKey && !in_array($primaryKey, $propertyNames)) {
            foreach ($propertyNames as $propertyName) {
                if (strtolower($propertyName) === strtolower($primaryKey)) {
                    $primaryKey = $propertyName;
                    break;
                }
            }
        }

        $primaryKeyStr = ($primaryKey)? '"' . $primaryKey . '"' : 'null';

        $processingProperties = $this->propertiesToProcessingPropString($properties);
        $doctrineTypeProperties = $this->propertiesToDoctrineTypeString($properties);
        $platformClassFQCN = get_class($connection->getDatabasePlatform());

        return <<<TABLE_ROW
<?php
/*
 * This file was auto generated by SqlConnector\Service\TableConnectorGenerator.
 * (c) prooph software GmbH <contact@prooph.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace {$namespace};

use Prooph\Processing\Type\Description\Description;
use Prooph\Processing\Type\Description\NativeType;
use Prooph\Link\Application\DataType\SqlConnector\TableRow;

class {$className} extends TableRow
{
    /**
     * @var array list of doctrine types indexed by property name
     */
    protected static \$propertyDbTypes = {$doctrineTypeProperties};

    /**
     * @var string Doctrine database platform class
     */
    protected static \$platformClass = '{$platformClassFQCN}';

    /**
     * @return array[propertyName => Prototype]
     */
    public static function getPropertyPrototypes()
    {
        return {$processingProperties};
    }

    /**
     * @return Description
     */
    public static function buildDescription()
    {
        return new Description("{$className}", NativeType::DICTIONARY, {$hasPrimaryKey}, {$primaryKeyStr});
    }
}
TABLE_ROW;
    }

    private function generateRowCollectionTypeClass($namespace, $collectionClassName, $rowClassName)
    {
        return <<<COLLECTION
<?php
/*
 * This file was auto generated by SqlConnector\Service\TableConnectorGenerator.
 * (c) prooph software GmbH <contact@prooph.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace {$namespace};

use Prooph\Processing\Type\AbstractCollection;
use Prooph\Processing\Type\Description\Description;
use Prooph\Processing\Type\Description\NativeType;
use Prooph\Processing\Type\Prototype;

class {$collectionClassName} extends AbstractCollection
{
    /**
     * Returns the prototype of the items type
     *
     * A collection has always one property with name item representing the type of all items in the collection.
     *
     * @return Prototype
     */
    public static function itemPrototype()
    {
        return {$rowClassName}::prototype();
    }

    /**
     * @return Description
     */
    public static function buildDescription()
    {
        return new Description("{$rowClassName} List", NativeType::COLLECTION, false);
    }
}
COLLECTION;
    }
} 