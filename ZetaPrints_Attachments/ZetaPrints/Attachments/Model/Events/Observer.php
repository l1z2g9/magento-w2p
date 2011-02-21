<?php
/**
 * @author 			Petar Dzhambazov
 * @category    ZetaPrints
 * @package     ZetaPrints_Attachments
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class ZetaPrints_Attachments_Model_Events_Observer
{

  /**
   * Add uploaded files to order
   *
   * Since we are going to handle file upload asynchronously
   * we need a way to attach files and orders.
   *
   * @deprecated after 1.4.2
   * @event checkout_cart_product_add_after
   * @param Varien_Event_Observer $observer
   */
  public function addAttachemntsToQuote($observer)
  {
    $quote_item = $observer->getEvent()->getQuoteItem();
    /*@var $quote_item Mage_Sales_Model_Quote_Item */
    $product_id = $quote_item->getProductId();
    $product = $quote_item->getProduct();

    $attachments = Mage::helper('attachments')->getSessionAttachments($product_id);

    $orderAtt = array ();
    foreach ($attachments as $data) {
      $atCollection = Mage::getModel('attachments/attachments')->getAttachmentCollection($data); // get all uploads for give option
      foreach ($atCollection as $att) { // collect all uploads in common array
        $orderAttValue = unserialize($att->getAttachmentValue());
        $orderAttValue['attachment_id'] = $att->getId();
        $orderAtt[$att->getProductId()][$att->getOptionId()][] = $orderAttValue;
      }
    }

    $optKey = 'option_';
    foreach ($orderAtt as $prid => $options) {
      if ($product_id == $prid) {
        foreach ($options as $optId => $value) {
          $option['code'] = $optKey . $optId;
          $option['product_id'] = $prid;
          $option['value'] = serialize($value);
          $quote_item->addOption($option);
          $o = $quote_item->getOptionByCode($option['code']);
          $product->addCustomOption($optId, $option['value']);
          $optionIDs = $this->_getItemOptionIds($quote_item);
          if(!in_array($optId, $optionIDs)){
            $optionIDs[] = $optId;
            $this->_setItemOptionIds($quote_item, $optionIDs);
          }
        }
      }
    }
  }

  protected function _getItemOptionIds(Mage_Sales_Model_Quote_Item $item)
  {
    $options = array ();
    if ($optionIds = $item->getOptionByCode('option_ids')) {
      $options = explode(',', $optionIds->getValue());
    }
    return $options;
  }

  protected function _setItemOptionIds(Mage_Sales_Model_Quote_Item $item, array $ids)
  {
    $optionIds = $item->getOptionByCode('option_ids');
    if(null == $optionIds){
      $opt = array('code' => 'option_ids', 'product_id' => $item->getProductId(), 'value' => implode(',', $ids));
      $item->addOption($opt);
      $product = $item->getProduct();
      if(!$product->getCustomOption('option_ids')){
        $product->addCustomOption('option_ids', implode(',', $ids));
      }
    }else{
      $optionIds->setValue(implode(',', $ids));
    }
  }

  /**
   * Store attachment keys into session
   * @event controller_action_predispatch_checkout_cart_add
   * @param Varien_Event_Observer $observer
   */
  public function storeAttachments($observer)
  {
    $request = $observer->getEvent()->getControllerAction()->getRequest();

    $prid = $request->getParam('product'); // get product id
    $hash = $request->getParam('attachment_hash');
    // to locate correct attachments we need all 3 keys
    if (isset($prid, $hash)) {
      Mage::helper('attachments')->setSessionAttachments($prid, $hash);
    }
  }

  /**
   * Update order id in attachment table
   * @event sales_order_save_after
   * @param Varien_Event_Observer $observer
   */
  public function updateAttachmentOrder($observer)
  {
    $order = $observer->getEvent()->getDataObject();
    /* @var $order Mage_Sales_Model_Order  */
    $items = $order->getAllItems();
    foreach ($items as $item) {
      $this->_addOrderId($item);
    }
  }

  protected function _addOrderId(Mage_Sales_Model_Order_Item $item)
  {
    $product_options = $item->getProductOptions(); // get product options
    $product = $item->getProduct(); // get product
    $order_id = $item->getOrder()->getRealOrderId(); // get order id as shown to user
    if ($product_options) {
      if (is_array($product_options) && isset($product_options['options'])) {
        foreach ($product_options['options'] as $option) {
          if ($this->_isOptionAttachment($option, $product)) {
            $atColl = Mage::getModel('attachments/attachments')->loadFromOptionArray($option);
            if($atColl && $atColl instanceof ZetaPrints_Attachments_Model_Mysql4_Attachments_Collection){
              foreach ($atColl as $atModel) {
                $atModel->addOrderId($order_id);
              }
            }
          }
        }
      }
    }
  }

  protected function _isOptionAttachment($option, $product)
  {
    if(!isset($option['option_type'])){
      return false;
    }
    $return = $option['option_type'] == ZetaPrints_Attachments_Model_Product_Option::OPTION_TYPE_ATTACHMENT
                                              && Mage::helper('attachments/upload')->getUseAjax($product);
    return $return;
  }

  /**
   * Delete old and obsolete files
   *
   * Not all files that are uploaded on product page
   * end up being part of an order. These files are
   * obsolete and only fill up hard disk.
   * Also after certain period of time, you have completed
   * your orders and thus files attached to them are of no
   * use besides taking up space.
   * We are handling these files as 2 cases - files that do not
   * belong to an order (orphaned) and old files (these are files that have
   * been uploaded some period ago regardless if they are part of an order or not)
   * This method is used to delete both of those file cases.
   * For it to function correctly you have to set periods for either
   * option in System > Configuration > Attachments Options > Settings
   * If any of the periods is 0 or empty ot non integer value,
   * it will be ignored and that type of files will not be deleted.
   * By default the settings are to delete obsolete files after 30 days
   * and not to delete old files at all.
   * Bare in mind that Old files include both orphaned files and files used in
   * orders, so if you specify value for old files that is equal or less
   * than the value for orphaned files, the latter is simply ignored since ALL files
   * will be deleted by the former setting anyway.
   *
   * This is run using Magento cron tab. Recomennded setting for cron tab
   * in production environment is: * /5 * * * * /path/to/php /path/to/magento/cron.php >/dev/null 2>&1
   * for testing and debugging purposes last portion of the line
   * could be changed to a log file:
   * * /5 * * * * /path/to/php /path/to/magento/cron.php >>/path/to/magento/var/log/cron.log
   * This way all output from the operation will be logged.
   *
   * @return void
   */
  public function cleanUpOldFiles()
  {
    $baseNode = 'attachments/settings/';
    $oldFiles = $baseNode . 'att_old_days';
    $orphanFiles = $baseNode . 'att_orphan_days';
    $oldNode = Mage::getStoreConfig($oldFiles);
    $orphanNode = Mage::getStoreConfig($orphanFiles);
    $this->_debug('Old files period: ' . $oldNode);
    $this->_debug('Orphan files period: ' . $orphanNode);

    // var_dump($oldNode, $orphanNode);
    if($oldNode && is_numeric($oldNode) && $oldNode > 0 ||
          $orphanNode && is_numeric($orphanNode) && $orphanNode > 0){ // if any of the periods is set


      $model = Mage::getModel('attachments/attachments');

      $collection = $model->getCollection();
      $collection->addFieldToSelect('*');
      $select = $collection->getSelect();

      $this->_setConditions($oldNode, $orphanNode, $select);

      $this->_debug('Query executed' . $select);

      $collection->load();
      $this->_debug(count($collection) . ' files will be deleted');
      try{
        $counter = 0;
        foreach($collection as $item){
          // $item->deleteFile();
          $counter++;
        }
        $this->_debug($counter . ' items deleted');
      }catch(Exception $e){
        $this->_debug($e->getMessage());
      }
    }else{
      $this->_debug('No files found');
    }
  }

  /**
   * Add appropriate condition to selection
   *
   * @param  integer $old - Old files period
   * @param  integer $orphaned - Orphaned files period
   * @param Varien_Db_Select $select - DB Select Object
   * @return Varien_Db_Select
   */
  protected function _setConditions($old, $orphaned, Varien_Db_Select $select)
  {
    $periodField = ZetaPrints_Attachments_Model_Attachments::ATT_CREATED; // table field 'created'
    $orderField = ZetaPrints_Attachments_Model_Attachments::ORD_ID; // table field 'order_id'
    $orphanedWhere = "DATE_SUB(CURDATE(),INTERVAL ? DAY) > `{$periodField}` AND `{$orderField}` IS NULL"; // orphaned condition
    $oldWhere = "DATE_SUB(CURDATE(),INTERVAL ? DAY) > `{$periodField}`"; // old condition

    if ($old && $orphaned) { // if both periods are greater than 0 - add both conditions

      if($orphaned < $old){  // if $orphaned period is sooner then $old, add them both
        $select->where($orphanedWhere, $orphaned)
              ->orWhere($oldWhere, $old);
      }else{ // else add just old
        $select->where($oldWhere, $old);
      }

    } else if ($orphaned) { // else add which ever is set
      $select->where($orphanedWhere, $orphaned);
    } else {
      $select->where($oldWhere, $old);
    }

    return $select;
  }

  /**
   * Echo debug value
   *
   * Only echo in developer mode.
   */
  protected function _debug($value)
  {
    echo date('r') . ' [DEBUG]: ' . $value . PHP_EOL;
    /*
    if (isset($_SERVER['MAGE_IS_DEVELOPER_MODE'])) {
      echo date('r') . ' [DEBUG]: ' . $value . PHP_EOL;
    }
    */
  }
}
