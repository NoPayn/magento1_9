<?php

$installer = $this;
$installer->startSetup();

$tableName = $installer->getTable('nopayn_transactions');

if (!$installer->getConnection()->isTableExists($tableName)) {
    $table = $installer->getConnection()
        ->newTable($tableName)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary'  => true,
        ])
        ->addColumn('order_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
        ])
        ->addColumn('order_increment_id', Varien_Db_Ddl_Table::TYPE_TEXT, 50, [
            'nullable' => false,
        ])
        ->addColumn('nopayn_order_id', Varien_Db_Ddl_Table::TYPE_TEXT, 255, [
            'nullable' => false,
        ])
        ->addColumn('payment_method', Varien_Db_Ddl_Table::TYPE_TEXT, 64, [
            'nullable' => false,
        ])
        ->addColumn('amount', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
        ])
        ->addColumn('currency', Varien_Db_Ddl_Table::TYPE_TEXT, 3, [
            'nullable' => false,
        ])
        ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 32, [
            'nullable' => false,
            'default'  => 'new',
        ])
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, [
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
        ])
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, [
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT_UPDATE,
        ])
        ->addIndex(
            $installer->getIdxName('nopayn_transactions', ['nopayn_order_id']),
            ['nopayn_order_id']
        )
        ->addIndex(
            $installer->getIdxName('nopayn_transactions', ['order_id']),
            ['order_id']
        )
        ->setComment('NoPayn Payment Transactions');

    $installer->getConnection()->createTable($table);
}

$installer->endSetup();
