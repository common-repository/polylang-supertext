<?php

namespace Supertext\Backend;

use Supertext\Api\WriteBack;
use Supertext\Helper\Constant;

class CallbackHandler
{
  /**
   * @var \Supertext\Helper\Library
   */
  private $library;

  /**
   * @var Log
   */
  private $log;

  /**
   * @var ContentProvider
   */
  private $contentProvider;

  /**
   * @param \Supertext\Helper\Library $library
   * @param Log $log
   * @param ContentProvider $contentProvider
   */
  public function __construct($library, $log, $contentProvider)
  {
    $this->library = $library;
    $this->log = $log;
    $this->contentProvider = $contentProvider;
  }

  /**
   * Handles a callback request
   */
  public function handleRequest()
  {
    $requestBody = file_get_contents('php://input');
    $json = json_decode($requestBody);

    if ($requestBody === true || !empty($json)) {
      try {
        $this->handleExternalWriteBackRequest($json);
      } catch (\Exception $e) {
        self::returnResponse(500, array('message' => $e->getMessage()));
      }
    } else {
      self::returnResponse(400, array('message' => 'Invalid request body'));
    }
  }

  /**
   * @param $json
   */
  public function handleInternalWriteBackRequest($json)
  {
    $writeBack = new WriteBack($json, $this->library);

    if (!$writeBack->isReferenceValid()) {
      throw new \Exception("Invalid reference.");
    }

    $this->writeBackOrder($writeBack);
  }

  /**
   * @param $json
   */
  private function handleExternalWriteBackRequest($json)
  {
    $writeBack = new WriteBack($json, $this->library);

    if (!$writeBack->isReferenceValid()) {
      self::returnResponse(403, array('message' => $this->getReferenceErrorMessage($writeBack)));
    }

    $this->writeBackOrder($writeBack);
    self::returnResponse(200, array('message' => 'The translation was saved successfully'));
  }

  /**
   * @param $code
   * @param $body
   */
  private static function returnResponse($code, $body)
  {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode($body);
    die();
  }

  /**
   * @param WriteBack $writeBack
   */
  private function writeBackOrder($writeBack)
  {
    $errors = array();
    $contentData = $writeBack->getContentData();
    $orderType = $writeBack->getOrderType();

    foreach ($writeBack->getSourcePostIds() as $sourcePostId) {
      if ('translation' == $orderType) {
        $targetPostId = $this->library->getMultilang()->getPostInLanguage($sourcePostId, $writeBack->getTargetLanguageCode());
      } else {
        $targetPostId = $sourcePostId;
      }

      if ($targetPostId == null) {
        $errors[$sourcePostId] = 'There is no linked post for saving the writeback.';
        continue;
      }

      // Get the translation post object
      $targetPost = get_post($targetPostId);
      $workflowSettings = $this->library->getSettingOption(Constant::SETTING_WORKFLOW);

      // Get translation or proofread meta
      $writeBackMeta = $writeBack->getWriteBackMeta($targetPost->ID);

      $isPostWritable =
        $targetPost->post_status == 'draft' ||
        ($targetPost->post_status == 'publish' && isset($workflowSettings['overridePublishedPosts']) && $workflowSettings['overridePublishedPosts']) ||
        $writeBackMeta->isInProgress();

      if (!$isPostWritable) {
        $errors[$sourcePostId] = 'The post for saving the ' . $writeBackMeta->getOrderType() . ' is not writable.';
        continue;
      }

      $this->contentProvider->saveContentMetaData($targetPost, $writeBackMeta->getContentMetaData());

      $targetContent = apply_filters(Constant::FILTER_WRITEBACK_TARGET_CONTENT, $contentData[$sourcePostId], $sourcePostId, $targetPostId, $this->library->getMultilang());

      $this->contentProvider->saveContentData($targetPost, $targetContent);

      if (isset($workflowSettings['publishOnCallback'])  && $workflowSettings['publishOnCallback']) {
        $targetPost->post_status = 'publish';
      }

      // Now finally save that post and flush cache
      wp_update_post($targetPost);

      // Let other plugins finish any additional writeback work needed on the target post
      do_action(Constant::ACTION_FINISH_TARGET_POST_WRITEBACK, $targetContent, $targetPostId, $sourcePostId, $this->library->getMultilang());

      // All good, set translation flag false
      $writeBackMeta->markAsComplete();

      $this->log->addEntry($targetPostId, $writeBackMeta->getSuccessLogEntry());
    }

    if (count($errors)) {
      $message = 'Errors: ';
      foreach ($errors as $sourcePostId => $error) {
        $message .= "Concerning post with id $sourcePostId" . ' -> ' . $error;
      }
      self::returnResponse(500, array('message' => $message));
    }
  }

  /**
   * @param WriteBack $writeBack
   * @return string
   */
  private function getReferenceErrorMessage($writeBack)
  {
    $sourcePostIds = $writeBack->getSourcePostIds();
    $orderId = $writeBack->getOrderId();
    $isOrderIdMismatch = false;
    $orderIdMessage = '';
    foreach ($sourcePostIds as $sourcePostId) {
      $targetLanguageCode = $writeBack->getTargetLanguageCode();
      $targetPostId = $this->library->getMultilang()->getPostInLanguage($sourcePostId, $targetLanguageCode);
      $postOrderId = $this->log->getLastOrderId($targetPostId);
      $isOrderIdMismatch = $isOrderIdMismatch || $orderId !== $postOrderId;
      $orderIdMessage .= " The post $sourcePostId was last ordered with order $postOrderId for $targetLanguageCode.\n";
    }

    if (!$isOrderIdMismatch) {
      return 'Error: reference is invalid.';
    }

    return "Error: reference is invalid. You cannot use this order to write back. One or more posts of this order have been reordered:" . $orderIdMessage;
  }
}
