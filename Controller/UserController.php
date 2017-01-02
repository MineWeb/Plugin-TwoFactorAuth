<?php
class UserController extends TwoFactorAuthAppController {

  public function validLogin() {
    $this->response->type('json');
    $this->autoRender = false;

    // valid request
    if (!$this->request->is('post'))
      throw new NotFoundException('Not post');
    if (!$this->Session->read('user_id_two_factor_auth'))
      return $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('TWOFACTORAUTH__LOGIN_INFOS_NOT_FOUND'))));
    if (empty($this->request->data['code']))
      return $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('TWOFACTORAUTH__LOGIN_CODE_EMPTY'))));

    // find user
    $user = $this->User->find('first', array('conditions' => array('id' => $this->Session->read('user_id_two_factor_auth'))));
    if (empty($user))
      return $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('TWOFACTORAUTH__LOGIN_INFOS_NOT_FOUND'))));

    // get user infos
    $this->loadModel('TwoFactorAuth.UsersSecret');
    $infos = $this->UsersSecret->find('first', array('conditions' => array('user_id' => $user['User']['id'])));
    if (empty($infos) || !$infos['UsersSecret']['enabled'])
      return $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('TWOFACTORAUTH__LOGIN_INFOS_NOT_FOUND'))));

    // include library & init
    require ROOT.DS.'app'.DS.'Plugin'.DS.'TwoFactorAuth'.DS.'Vendor'.DS.'PHPGangsta'.DS.'GoogleAuthenticator.php';
    $ga = new PHPGangsta_GoogleAuthenticator();

    // check code
    $checkResult = $ga->verifyCode($infos['UsersSecret']['secret'], $this->request->data['code'], 2);    // 2 = 2*30sec clock tolerance
    if (!$checkResult)
      return $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('TWOFACTORAUTH__LOGIN_CODE_INVALID'))));

    // remove TwoFactorAuth session
    $this->Session->delete('user_id_two_factor_auth');

    // login
    if($this->request->data['remember_me'])
      $this->Cookie->write('remember_me', array('pseudo' => $user['User']['pseudo'], 'password' => $user['User']['password'], true, '1 week'));

    $this->Session->write('user', $user['User']['id']);

    $event = new CakeEvent('afterLogin', $this, array('user' => $this->User->getAllFromUser($user['User']['pseudo'])));
    $this->getEventManager()->dispatch($event);
    if($event->isStopped()) {
      return $event->result;
    }

    $this->response->body(json_encode(array('statut' => true, 'msg' => $this->Lang->get('USER__REGISTER_LOGIN'))));
  }

  public function generateSecret() {
    $this->response->type('json');
    $this->autoRender = false;

    // valid request
    if (!$this->isConnected)
      throw new ForbiddenException('Not logged');

    // include library & init
    require ROOT.DS.'app'.DS.'Plugin'.DS.'TwoFactorAuth'.DS.'Vendor'.DS.'PHPGangsta'.DS.'GoogleAuthenticator.php';
    $ga = new PHPGangsta_GoogleAuthenticator();

    // generate and set into session
    $secret = $ga->createSecret();
    $qrCodeUrl = $ga->getQRCodeGoogleUrl($this->User->getKey('pseudo'), $secret, $this->Configuration->getKey('name'));
    $this->Session->write('two-factor-auth-secret', $secret);

    // send to user
    $this->response->body(json_encode(array('qrcode_url' => $qrCodeUrl, 'secret' => $secret)));
  }

  public function validEnable() {
    $this->response->type('json');
    $this->autoRender = false;

    // valid request
    if (!$this->request->is('post'))
      throw new NotFoundException('Not post');
    if (!$this->isConnected)
      throw new ForbiddenException('Not logged');
    if (empty($this->request->data['code']))
      return $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('TWOFACTORAUTH__LOGIN_CODE_EMPTY'))));
    if (!$this->Session->read('two-factor-auth-secret'))
      return $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('TWOFACTORAUTH__SECRET_NOT_FOUND'))));
    $secret = $this->Session->read('two-factor-auth-secret');

    // include library & init
    require ROOT.DS.'app'.DS.'Plugin'.DS.'TwoFactorAuth'.DS.'Vendor'.DS.'PHPGangsta'.DS.'GoogleAuthenticator.php';
    $ga = new PHPGangsta_GoogleAuthenticator();

    // check code
    $checkResult = $ga->verifyCode($secret, $this->request->data['code'], 2);    // 2 = 2*30sec clock tolerance
    if (!$checkResult)
      return $this->response->body(json_encode(array('statut' => false, 'msg' => $this->Lang->get('TWOFACTORAUTH__LOGIN_CODE_INVALID'))));

    // remove TwoFactorAuth session
    $this->Session->delete('two-factor-auth-secret');

    // save into db
    $this->loadModel('TwoFactorAuth.UsersSecret');
    if ($infos = $this->UsersSecret->find('first', array('conditions' => array('user_id' => $this->User->getKey('id')))))
      $this->UsersSecret->read(null, $infos['UsersSecret']['id']);
    else
      $this->UsersSecret->create();
    $this->UsersSecret->set(array('secret' => $secret, 'enabled' => true, 'user_id' => $this->User->getKey('id')));
    $this->UsersSecret->save();

    // send to user
    $this->response->body(json_encode(array('statut' => true, 'msg' => $this->Lang->get('TWOFACTORAUTH__SUCCESS_ENABLED_TWO_FACTOR_AUTH'))));
  }

  public function disable() {
    $this->response->type('json');
    $this->autoRender = false;

    // valid request
    if (!$this->isConnected)
      throw new ForbiddenException('Not logged');

    // save into db
    $this->loadModel('TwoFactorAuth.UsersSecret');
    $infos = $this->UsersSecret->find('first', array('conditions' => array('user_id' => $this->User->getKey('id'))));
    $this->UsersSecret->read(null, $infos['UsersSecret']['id']);
    $this->UsersSecret->set(array('enabled' => false));
    $this->UsersSecret->save();

    // send to user
    $this->response->body(json_encode(array('qrcode_url' => $qrCodeUrl, 'secret' => $secret)));
  }

}
