<?php

class E2GIPEmail extends E2GIP {
  protected $type = 'email';

  private function _get_instance() {
    return (object) [];
  }

  public function get_auth_url() {
    return '';
  }

  public static function get_profile_url($id, $link) {
    return false;
  }

  public function callback() {
    return true;
  }

}