<?php

$installer = $this;
$installer->startSetup();

$tableName = $installer->getTable('nopayn_transactions');

$connection = $installer->getConnection();

if (!$connection->tableColumnExists($tableName, 'capture_mode')) {
    $connection->addColumn($tableName, 'capture_mode', [
        'type'    => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'  => 16,
        'default' => 'auto',
        'comment' => 'Capture mode: auto or manual',
    ]);
}

if (!$connection->tableColumnExists($tableName, 'transaction_id')) {
    $connection->addColumn($tableName, 'transaction_id', [
        'type'    => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'  => 255,
        'default' => null,
        'comment' => 'NoPayn transaction ID for capture/void',
    ]);
}

$installer->endSetup();
