<?php

namespace Supertext\Backend;

use Supertext\Helper\Library;
use Supertext\Proofreading\Proofreading;
use Supertext\Helper\Constant;
use Supertext\Helper\TranslationMeta;
use Supertext\Helper\View;

/**
 * Serves as a helper for the translation inject to the user
 * @package Supertext\Backend
 */
class AdminExtension
{
  /**
   * @var string the translation column id
   */
  const TRANSLATION_STATUS_COLUMN = 'translation-status';

  /**
   * @var meta box id
   */
  const META_BOX = 'sttr_meta_box';

  /**
   * @var \Supertext\Helper\Library
   */
  private $library;

  /**
   * @var Log
   */
  private $log;

  /**
   * @var null|string
   */
  private $screenBase = null;

  /**
   * @var null|string
   */
  private $screenAction = null;

  /**
   * @var bool
   */
  private $isBlockEditor = false;

  /**
   * @var null|array
   */
  private $currentPostId = null;

  /**
   * @var bool
   */
  private $isCurrentPostInTranslation = false;

  /**
   * Various filters to change and/or display things
   */
  public function __construct($library, $log)
  {
    $this->library = $library;
    $this->log = $log;

    add_action('current_screen', array($this, 'setScreenData'));
    add_action('admin_enqueue_scripts', array($this, 'addBackendAssets'));
    add_action('admin_notices', array($this, 'showPluginStatusMessages'));
    add_action('admin_notices', array($this, 'showInTranslationMessage'));
    add_action('admin_footer', array($this, 'addJavascriptContext'));
    add_action('admin_footer', array($this, 'addTemplates'));
    add_action('add_meta_boxes', array($this, 'addMetaBox'));

    add_filter('manage_posts_columns', array($this, 'addTranslationStatusColumn'), 100);
    add_filter('manage_media_columns', array($this, 'addTranslationStatusColumn'), 100);
    add_action('manage_posts_custom_column', array($this, 'displayTranslationStatusColumn'), 12, 2);
    add_action('manage_media_custom_column', array($this, 'displayTranslationStatusColumn'), 12, 2);
    add_filter('manage_pages_columns', array($this, 'addTranslationStatusColumn'), 100);
    add_filter('manage_media_columns', array($this, 'addTranslationStatusColumn'), 100);
    add_action('manage_pages_custom_column', array($this, 'displayTranslationStatusColumn'), 12, 2);
    add_action('manage_media_custom_column', array($this, 'displayTranslationStatusColumn'), 12, 2);

    if (isset($_GET[Constant::NEW_POST_AUTO_SAVE_FLAG]) && isset($_GET['source_post'])) {
      add_filter('wp_insert_post_data', array($this, 'setNewTargetPostData'), 1000, 1);
      add_action('save_post', array($this, 'assignLanguageToNewTargetPost'), 10, 1);
      add_action('save_post', array($this, 'addMetadataToNewTargetPost'), 1000, 1);
    }
  }

  /**
   * @param \WP_Screen $screen the screen shown
   */
  public function setScreenData($screen)
  {
    $this->screenBase = $screen->base;
    $this->screenAction = empty($screen->action) ? empty($_GET['action']) ? '' : $_GET['action'] : $screen->action;
    $this->isBlockEditor = method_exists($screen, 'is_block_editor') && $screen->is_block_editor();
    $this->currentPostId = isset($_GET['post']) ? intval($_GET['post']) : 0;
    $this->isCurrentPostInTranslation = isset($_GET['post'])  && TranslationMeta::of($this->currentPostId)->is(TranslationMeta::IN_TRANSLATION);
  }

  /**
   * Add the global backend libraries and css
   */
  public function addBackendAssets()
  {
    //Settings assets
    if ($this->isSettingsScreen()) {
      wp_enqueue_style(Constant::SETTINGS_STYLE_HANDLE);
      wp_enqueue_style(Constant::JSTREE_STYLE_HANDLE);

      wp_enqueue_script(Constant::SETTINGS_SCRIPT_HANDLE);
      wp_enqueue_script(Constant::JSTREE_SCRIPT_HANDLE);
      wp_enqueue_script(Constant::JQUERY_UI_AUTOCOMPLETE);
    }

    if ($this->isEditPostScreen() || $this->isPostsScreen()) {
      wp_enqueue_style(Constant::ADMIN_EXTENSION_STYLE_HANDLE);

      wp_enqueue_script(Constant::ADMIN_EXTENSION_SCRIPT_HANDLE);
    }

    if ($this->isEditPostScreen() && $this->isBlockEditor) {
      wp_enqueue_script(Constant::BLOCK_EDITOR_SCRIPT_HANDLE);
    }
  }

  /**
   * Show plugin informations
   */
  public function showPluginStatusMessages()
  {
    if (!$this->isEditPostScreen() && !$this->isPostsScreen() && !$this->isSettingsScreen()) {
      return;
    }

    $pluginStatus = $this->library->getPluginStatus();

    if (!$pluginStatus->isCurlActivated) {
      echo '
        <div class="notice notice-warning is-dismissible">
          <p>' . __('The PHP function <em>curl_exec</em> is disabled. Please enable it in order to be able to send requests to Supertext.', 'supertext') . '</p>
        </div>
      ';
    }

    if ($this->isSettingsScreen()) {
      return;
    }

    if (!$pluginStatus->isUserMappingConfiguredProperly) {
      echo '
        <div class="notice notice-warning is-dismissible">
          <p>' . __('The Supertext plugin cannot be used. Please configure at least one Supertext account in the Supertext settings.', 'supertext') . '</p>
        </div>
      ';
    }

    if ($pluginStatus->isMultilangActivated && !$pluginStatus->isLanguageMappingConfiguredProperly) {
      echo '
        <div class="notice notice-warning is-dismissible">
          <p>' .  __('The Supertext translation feature cannot be used. Please configure the language mapping in the Supertext settings.', 'supertext') . '</p>
        </div>
      ';
    }
  }

  /**
   * Show information about the article translation, if given
   */
  public function showInTranslationMessage()
  {
    if (!$this->isEditPostScreen()) {
      return;
    }

    $orderId = $this->log->getLastOrderId($this->currentPostId);

    // Show info if there is an order and the article is not translated yet
    if (intval($orderId) > 0 && $this->isCurrentPostInTranslation) {
      echo '
        <div class="updated">
          <p>' . sprintf(__('The post was sent to Supertext and is now being translated. Your order number is %s.', 'supertext'), intval($orderId)) . '</p>
        </div>
      ';
    }
  }

  /**
   * Adds the javascript context data
   */
  public function addJavascriptContext()
  {
    if (!$this->isEditPostScreen() && !$this->isPostsScreen()) {
      return;
    }

    $pluginStatus = $this->library->getPluginStatus();
    $newPostUrls = $this->library->getMultilang()->getNewPostUrls();
    $enable =
      $pluginStatus->isCurlActivated &&
      $pluginStatus->isUserMappingConfiguredProperly &&
      $pluginStatus->isCurrentUserConfigured;

    $context = array(
      'enable' => $enable,
      'isMultilangReady' => $pluginStatus->isMultilangActivated && $pluginStatus->isLanguageMappingConfiguredProperly,
      'screen' => $this->screenBase,
      'currentPostId' => $this->currentPostId,
      'isCurrentPostInTranslation' => $this->isCurrentPostInTranslation,
      'resourceUrl' => get_bloginfo('wpurl'),
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'newPostUrls' => $newPostUrls,
      'newPostAutoSaveFlag' => Constant::NEW_POST_AUTO_SAVE_FLAG
    );

    $contextJson = json_encode($context);

    echo '<script type="text/javascript">
            var Supertext = Supertext || {};
            Supertext.Context = ' . $contextJson . ';
          </script>';
  }

  /**
   * Add admin extension templates
   */
  public function addTemplates()
  {
    if ($this->isEditPostScreen() || $this->isPostsScreen()) {
      $view = new View('templates/admin-extension-templates');
      $view->render();
    }
  }

  /**
   * Show supertext translation information
   */
  public function addMetaBox()
  {
    //return if not edit screen and not existing post
    if (!$this->isEditPostScreen() || $this->currentPostId <= 0) {
      return;
    }

    add_meta_box(self::META_BOX, __('Supertext', 'supertext'), array($this, 'displayMetaBoxView'), null, 'side');

    Proofreading::getInstance()->setupMetabox();
  }

  /**
   * Shows meta box view
   */
  public function displayMetaBoxView()
  {
    $meta = TranslationMeta::of($this->currentPostId);

    $status = array(
      'isTranslation' => $meta->is(TranslationMeta::TRANSLATION),
      'isInTranslation' => $meta->is(TranslationMeta::IN_TRANSLATION),
      'hasChangedSinceLastTranslation' => strtotime($meta->get(TranslationMeta::TRANSLATION_DATE)) <  strtotime(get_post_field('post_modified', $this->currentPostId))
    );

    $logEntries = $this->log->getLogEntries($this->currentPostId);
    $logEntries = array_reverse($logEntries);

    $workflowSettings = $this->library->getSettingOption(Constant::SETTING_WORKFLOW);

    $view = new View('backend/meta-box');
    $view->render(array(
      'status' => $status,
      'syncTranslationChanges' => isset($workflowSettings['syncTranslationChanges']) && $workflowSettings['syncTranslationChanges'],
      'logEntries' => $logEntries
    ));
  }

  public function addNewPostUrl($link, $language, $post_id)
  {
    if (!isset($this->newPostUrls[$post_id])) {
      $this->newPostUrls[$post_id] = array();
    }

    $this->newPostUrls[$post_id][$language->slug] = urldecode(html_entity_decode($link));

    return $link;
  }

  /**
   * Sets the translation status column cell
   * @param $column
   * @param $postId
   */
  public function displayTranslationStatusColumn($column, $postId)
  {
    if ($column != self::TRANSLATION_STATUS_COLUMN) {
      return;
    }

    if (TranslationMeta::of($postId)->is(TranslationMeta::IN_TRANSLATION)) {
      echo '<span class="dashicons dashicons-clock"></span>';
    }
  }

  /**
   * Adds a translation status column.
   * @param $columns
   * @return array
   */
  public function addTranslationStatusColumn($columns)
  {
    $newColumns = array();

    foreach ($columns as $key => $column) {
      if ($key == 'comments') {
        $newColumns[self::TRANSLATION_STATUS_COLUMN] = '<span class="dashicons dashicons-translation" width="20px"></span>';
      }

      $newColumns[$key] = $column;
    }

    if (!isset($newColumns[self::TRANSLATION_STATUS_COLUMN])) {
      $newColumns[self::TRANSLATION_STATUS_COLUMN] = '<span class="dashicons dashicons-translation" width="20px"></span>';
    }

    return $newColumns;
  }

  /**
   * Sets default data for new target post
   * @param $data post data as array
   */
  public function setNewTargetPostData($data)
  {
    $sourcePost = get_post($_GET['source_post']);

    $data['post_status'] = Constant::TRANSLATION_POST_STATUS;
    $data['post_title'] = $sourcePost->post_title . ' [' . __('In translation', 'supertext') . '...]';

    return $data;
  }

  public function assignLanguageToNewTargetPost($targetPostId)
  {
    $this->library->getMultilang()->assignLanguageToNewTargetPost($_GET['source_post'], $targetPostId, $_GET['target_lang']);
  }

  public function addMetadataToNewTargetPost($targetPostId)
  {
    $wpmlAW = $this->library->getMultilang();
    $wpmlAW->copyMetaData($_GET['source_post'], $targetPostId);
  }

  private function isEditPostScreen()
  {
    return $this->screenBase == 'post' && $this->screenAction == 'edit';
  }

  private function isPostsScreen()
  {
    return $this->screenBase == 'edit' || $this->screenBase == 'upload';
  }

  private function isSettingsScreen()
  {
    return $this->screenBase == 'settings_page_supertext-settings';
  }
}
