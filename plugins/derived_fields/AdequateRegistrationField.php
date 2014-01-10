<?php
namespace CIP;
require_once 'plugins/IDerivedField.php';
// require_once '../IDerivedField.php';

class AdequateRegistrationField implements IDerivedField {
  private $REQUIRED_FIELDS = array(
    'Digital oprettelsesdato' => '{5a193086-8b8e-424e-aa3c-3fd5abc4b8d6}',
    'Belysningsmetode'        => '{25a45303-26c4-4e40-b78d-0acd099484d1}',
    'Fotograf'                => '{9b071045-118c-4f42-afa1-c3121783ac66}',
    'Tilgængelighed'          => '{a493be21-0f70-4cae-9394-703eca848bad}',
    'Copyright'               => '{af4b2e2c-5f6a-11d2-8f20-0000c0e166dc}',
    'Licens'                  => '{f5d1dcd8-c553-4346-8d4d-672c85bb59be}',
    'Lagt ind af'             => '{af4b2e08-5f6a-11d2-8f20-0000c0e166dc}', // cataloging user
    'Kort titel'              => '{8df6fa66-4472-4baf-acad-0094441a17c1}', // beskrivelsesnote
    'Beskrivelse'             => '{2ce9c8eb-b83d-4a91-9d09-2141cac7de12}',
    'Optagelsestid, fra'      => '{65f668d1-b070-494f-a9a3-7b6c3d370e65}',
    'Optagelsestid, til'      => '{0db7de9e-37fa-47a5-b087-0b1e6066ee86}'
  );

  public function getUUID() { return '{dbafdb2c-2256-48e4-b882-0985a9eb507b}'; }
  public function getFieldName() { return 'Adequate Registration'; }

  public function createField($asset) {
    $bool_sum = TRUE;

    foreach ($this->REQUIRED_FIELDS as $name => $uuid) {
      $bool_sum = $bool_sum && isset($asset[$uuid]);
    }

    return array($this->getUUID() => $bool_sum);
  }

}
