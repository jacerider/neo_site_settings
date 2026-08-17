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
  if ($type !== 'site') {
    return $replacements;
  }

  // Only act when a token this module actually provides is present, so that an
  // unrelated site token such as [site:name] does not pick up a dependency on
  // the settings entity.
  $handled = FALSE;
  foreach (array_keys($tokens) as $tokenName) {
    if (in_array(strtok($tokenName, ':'), ['phone', 'address'], TRUE)) {
      $handled = TRUE;
      break;
    }
  }
  if (!$handled) {
    return $replacements;
  }

  /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
  $storage = \Drupal::entityTypeManager()->getStorage('neo_site_settings');
  $settings = $storage->loadByType('general');
  if (!$settings) {
    return $replacements;
  }

  // Register the dependency once, and unconditionally: a field that is empty
  // now must still invalidate these tokens when it is filled in later.
  $bubbleable_metadata->addCacheableDependency($settings);

  // Parse the address a single time, not once per address token.
  $addressString = $settings->hasField('field_address') && !$settings->get('field_address')->isEmpty()
    ? $settings->get('field_address')->value
    : NULL;
  $addressParts = $addressString !== NULL ? neo_site_settings_address_parse($addressString) : NULL;

  foreach ($tokens as $name => $original) {
    $params = explode(':', $name);
    $name = array_shift($params);
    $op = array_shift($params);
    switch ($name) {
      case 'phone':
        if ($settings->hasField('field_phone') && !$settings->get('field_phone')->isEmpty()) {
          $replacements[$original] = $settings->get('field_phone')->value;
        }
        break;

      case 'address':
        if ($addressParts !== NULL) {
          $replacements[$original] = match($op) {
            'street_1' => $addressParts['street_number'] . ' ' . $addressParts['street_name'],
            'street_2' => $addressParts['unit'] ? ', ' . $addressParts['unit'] : '',
            'city' => $addressParts['city'],
            'state' => $addressParts['state'],
            'zip' => $addressParts['zip'],
            'country' => $addressParts['country'],
            default => implode(', ', array_map('trim', explode("\n", $addressString))),
          };
        }
        break;
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
 *   - state: The state abbreviation (2 letters), upper-cased.
 *   - zip: The ZIP code (5 digits, optionally with 4-digit extension).
 *   - country: Always an empty string. No parse branch populates it; the key
 *     exists so that the [site:address:country] token resolves to empty rather
 *     than falling through to the whole address.
 *   All seven keys are always present. Parsing is best effort: any component
 *   the input does not match is returned as an empty string, so an unrecognised
 *   address yields an all-empty result rather than an error.
 */
function neo_site_settings_address_parse($address) {
  $result = [
    'street_number' => '',
    'street_name' => '',
    'unit' => '',
    'city' => '',
    'state' => '',
    'zip' => '',
    'country' => '',
  ];

  $address = trim(str_replace("\r\n", "\n", (string) $address));
  if ($address === '') {
    return $result;
  }

  // Peel the state and ZIP off the end first. Doing this before splitting means
  // the rest of the parse does not care whether the input was written across
  // lines or as one comma-separated string.
  if (preg_match('/,?\s*([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)\s*$/', $address, $matches)) {
    $result['state'] = strtoupper($matches[1]);
    $result['zip'] = $matches[2];
    $address = substr($address, 0, -strlen($matches[0]));
  }

  // Whatever is left is a list of parts, however the author separated them.
  $parts = preg_split('/\s*[\n,]\s*/', $address, -1, PREG_SPLIT_NO_EMPTY);
  $parts = array_values(array_filter(array_map('trim', $parts), 'strlen'));
  if (!$parts) {
    return $result;
  }

  // The last part is the city, but only when something precedes it or a state
  // and ZIP were found; a lone fragment is not enough to call it a city.
  if (count($parts) > 1 || $result['state'] !== '') {
    $result['city'] = array_pop($parts);
  }

  // The street is the first remaining part that opens with a house number,
  // rather than whichever part happens to come first: real addresses often
  // lead with a company or recipient line.
  $streetIndex = NULL;
  foreach ($parts as $index => $part) {
    if (preg_match('/^(\d+\w*)\s+(.+?)(?:\s+(apt|unit|suite|ste|#)\s*(.+))?$/i', $part, $matches)) {
      $result['street_number'] = $matches[1];
      $result['street_name'] = $matches[2];
      if (isset($matches[4]) && $matches[4] !== '') {
        $result['unit'] = $matches[3] . ' ' . $matches[4];
      }
      $streetIndex = $index;
      break;
    }
  }

  // Anything between the street and the city is the unit, unless the street
  // line already carried one inline.
  if ($streetIndex !== NULL && $result['unit'] === '') {
    $trailing = array_slice($parts, $streetIndex + 1);
    if ($trailing) {
      $result['unit'] = implode(' ', $trailing);
    }
  }

  return $result;
}
