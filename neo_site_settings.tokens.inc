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
    'name' => t('Site Settings: Phone Number'),
    'description' => t('The eXo site settings phone number.'),
  ];
  $data['tokens']['site']['address'] = [
    'name' => t('Site Settings: Address'),
    'description' => t('The eXo site settings address.'),
  ];
  $data['tokens']['site']['address:street_1'] = [
    'name' => t('Site Settings: Address Street 1'),
    'description' => t('The eXo site settings address street 1.'),
  ];
  $data['tokens']['site']['address:street_2'] = [
    'name' => t('Site Settings: Address Street 2'),
    'description' => t('The eXo site settings address street 2.'),
  ];
  $data['tokens']['site']['address:city'] = [
    'name' => t('Site Settings: Address City'),
    'description' => t('The eXo site settings address city.'),
  ];
  $data['tokens']['site']['address:state'] = [
    'name' => t('Site Settings: Address State'),
    'description' => t('The eXo site settings address state.'),
  ];
  $data['tokens']['site']['address:zip'] = [
    'name' => t('Site Settings: Address Zip'),
    'description' => t('The eXo site settings address zip code.'),
  ];
  $data['tokens']['site']['address:country'] = [
    'name' => t('Site Settings: Address Country'),
    'description' => t('The eXo site settings address country.'),
  ];
}

/**
 * Implements hook_tokens().
 */
function neo_site_settings_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];
  if ($type === 'site') {
    $cache = &drupal_static(__FUNCTION__);
    foreach ($tokens as $name => $original) {
      if (isset($cache[$original])) {
        $replacements[$original] = $cache[$original];
        continue;
      }
      $params = explode(':', $name);
      $name = array_shift($params);
      $op = array_shift($params);
      switch ($name) {
        case 'phone':
          /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
          $storage = \Drupal::entityTypeManager()->getStorage('neo_site_settings');
          $settings = $storage->loadByType('general');
          if ($settings) {
            $bubbleable_metadata->addCacheableDependency($settings);
            if ($settings->hasField('field_phone') && !$settings->get('field_phone')->isEmpty()) {
              $replacements[$original] = $settings->get('field_phone')->value;
            }
          }
          break;

        case 'address':
          /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
          $storage = \Drupal::entityTypeManager()->getStorage('neo_site_settings');
          $settings = $storage->loadByType('general');
          if ($settings) {
            $bubbleable_metadata->addCacheableDependency($settings);
            if ($settings->hasField('field_address') && !$settings->get('field_address')->isEmpty()) {
              $addressString = $settings->get('field_address')->value;
              $addressParts = neo_site_settings_address_parse($addressString);
              $replacements[$original] = match($op) {
                'street_1' => $addressParts['street_number'] . ' ' . $addressParts['street_name'],
                'street_2' => $addressParts['unit'] ? ', ' . $addressParts['unit'] : '',
                'city' => $addressParts['city'],
                'state' => $addressParts['state'],
                'zip' => $addressParts['zip'],
                'country' => $addressParts['country'],
                default => implode(', ', array_map('trim', explode("\n", $addressString))),
              };
              $cache[$original] = $replacements[$original];
            }
          }
          break;
      }
    }
  }
  return $replacements;
}

/**
 * Parses an address string into structured components.
 *
 * This function takes a raw address string and parses it into components such
 * as street number, street name, unit, city, state, zip, and country.
 *
 * The function handles both multi-line addresses and single-line
 * comma-separated addresses. For multi-line addresses, it expects the first
 * line to contain street information and the last line to contain city, state,
 * and zip information. For single-line addresses, it expects a format like
 * "123 Main St, City, ST 12345".
 *
 * @param string $address
 *   The raw address string to parse.
 *
 * @return array
 *   An associative array of address components with the following keys:
 *   - street_number: The street number portion of the address.
 *   - street_name: The street name portion of the address.
 *   - unit: The unit, apartment, or suite number if available.
 *   - city: The city name.
 *   - state: The state abbreviation (2 letters).
 *   - zip: The ZIP code (5 digits, optionally with 4-digit extension).
 *   - country: The country name (not currently parsed).
 *   Empty values are removed from the returned array.
 */
function neo_site_settings_address_parse($address) {
  $lines = array_map('trim', explode("\n", trim($address)));
  $result = [
    'street_number' => '',
    'street_name' => '',
    'unit' => '',
    'city' => '',
    'state' => '',
    'zip' => '',
    'country' => '',
  ];

  // Handle multi-line addresses.
  if (count($lines) > 1) {
    $streetLine = array_shift($lines);
    $lastLine = array_pop($lines);

    // Parse street line.
    if (preg_match('/^(\d+\w*)\s+(.+?)(?:\s+(apt|unit|suite|#)\s*(.+))?$/i', $streetLine, $matches)) {
      $result['street_number'] = $matches[1];
      $result['street_name'] = $matches[2];
      if (isset($matches[4])) {
        $result['unit'] = $matches[3] . ' ' . $matches[4];
      }
    }

    // Parse city, state, zip line.
    if (preg_match('/^(.+?),?\s+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)/', $lastLine, $matches)) {
      $result['city'] = trim($matches[1], ', ');
      $result['state'] = $matches[2];
      $result['zip'] = $matches[3];
    }
  }
  else {
    // Single line parsing.
    $parts = preg_split('/,\s*/', $address);
    if (count($parts) >= 3) {
      // Street.
      if (preg_match('/^(\d+\w*)\s+(.+)/', $parts[0], $matches)) {
        $result['street_number'] = $matches[1];
        $result['street_name'] = $matches[2];
      }

      // City.
      $result['city'] = $parts[1];

      // State and ZIP.
      if (preg_match('/([A-Z]{2})\s+(\d{5}(?:-\d{4})?)/', $parts[2], $matches)) {
        $result['state'] = $matches[1];
        $result['zip'] = $matches[2];
      }
    }
  }

  return $result;
}
