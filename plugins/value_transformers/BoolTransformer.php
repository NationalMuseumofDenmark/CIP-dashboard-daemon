<?php
namespace CIP;
require_once 'plugins/IValueTransformer.php';

class BoolTransformer implements IValueTransformer {
  public function transform($bool) {
    assert(is_bool($bool), 'BoolTransformer must be passed a boolean value');
    if ($bool === TRUE) {
      return 'TRUE';
    } else {
      return 'FALSE';
    }
  }
  public function getType() { return 'bool'; }
}
