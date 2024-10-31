<?php

namespace Supertext\TextAccessors;

use Supertext\Helper\Constant;
use Supertext\Helper\Library;
use Supertext\Helper\TextProcessor;
use Supertext\Helper\View;

/**
 * Class CustomFieldsTextAccessor
 * @package Supertext\TextAccessors
 */
class CustomFieldsTextAccessor implements ITextAccessor, ISettingsAware
{
  /**
   * @var TextProcessor text processor
   */
  private $textProcessor;

  /**
   * @var Library library
   */
  private $library;

  /**
   * @param TextProcessor $textProcessor
   * @param Library $library
   */
  public function __construct($textProcessor, $library)
  {
    $this->textProcessor = $textProcessor;
    $this->library = $library;
  }

  /**
   * Gets the content accessors name
   * @return string
   */
  public function getName()
  {
    return __('General custom fields', 'supertext');
  }

  /**
   * @param $postId
   * @return array
   */
  public function getTranslatableFields($postId)
  {
    $savedCustomFields = $this->library->getSettingOption(Constant::SETTING_CUSTOM_FIELDS);

    $translatableFields = array();

    foreach($savedCustomFields as $savedCustomField){

     if(!get_post_meta($postId, $savedCustomField, true)) {
      continue;
     }

     $translatableFields[] = array(
       'title' => $savedCustomField,
       'name' => $savedCustomField,
       'checkedPerDefault' => true
     );
    }

    return $translatableFields;
  }

  /**
   * @param $post
   * @return array
   */
  public function getRawTexts($post)
  {
    return get_post_meta($post->ID);
  }

  /**
   * @param $post
   * @param $selectedTranslatableFields
   * @return array
   */
  public function getTexts($post, $selectedTranslatableFields)
  {
    $texts = array();

    foreach($selectedTranslatableFields as $id => $selected){
      $texts[$id] = $this->textProcessor->replaceShortcodes(get_post_meta($post->ID, $id, true));
    }

    return $texts;
  }

  /**
   * @param $post
   * @param $texts
   */
  public function setTexts($post, $texts)
  {
    foreach($texts as $id => $text){
      $decodedContent = html_entity_decode($text, ENT_COMPAT | ENT_HTML401, 'UTF-8');
      $decodedContent = $this->textProcessor->replaceShortcodeNodes($decodedContent);

      $multiLang = $this->library->getMultilang();
      $targetLanguage = $multiLang->getPostLanguage($post->ID);
      $filteredValue = apply_filters(Constant::FILTER_POST_META_TRANSLATION, $value, $id, $targetLanguage, $multiLang);

      update_post_meta($post->ID, $id, $filteredValue);
    }
  }

  /**
   * @return array
   */
  public function getSettingsViewBundle()
  {
    $savedCustomFields = $this->library->getSettingOption(Constant::SETTING_CUSTOM_FIELDS);

    return array(
      'view' => new View('backend/settings-custom-fields'),
      'context' => array(
        'savedCustomFields' => $savedCustomFields
      )
    );
  }

  /**
   * @param $postData
   */
  public function saveSettings($postData)
  {
    $this->library->saveSettingOption(Constant::SETTING_CUSTOM_FIELDS, array_filter($postData['custom-fields']));
  }
}