<?php

/**
 * @file
 * Builds placeholder replacement tokens for neo-media-related data.
 */

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Impements hook_token_info_alter().
 */
function neo_site_settings_token_info_alter(&$data) {
  $data['tokens']['site']['phone'] = [
    'name' => t('Site Settings: Phone NUmber'),
    'description' => t('The eXo site settings phone number.'),
  ];
}

/**
 * Implements hook_tokens().
 */
function neo_site_settings_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];
  if ($type === 'site') {
    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'phone':
          /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
          $storage = \Drupal::entityTypeManager()->getStorage('neo_site_settings');
          $settings = $storage->loadByType('general');
          if ($settings->hasField('field_phone') && !$settings->get('field_phone')->isEmpty()) {
            $replacements[$original] = $settings->get('field_phone')->value;
          }
          break;
      }
    }
  }
  return $replacements;
}
