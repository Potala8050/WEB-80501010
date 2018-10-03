<?php
needRole('vhost');
class DomainControl extends Control
{
	public function __construct()
	{
		parent::__construct();
	}

	public function __destruct()
	{
		parent::__destruct();
	}

	public function show()
	{
		$vhost = getRole('vhost');
		$user = $_SESSION['user'][$vhost];
		$cname_host = daocall('setting', 'get', array('cname_host'));

		if ($cname_host) {
			$domain_note = '.' . $cname_host . '';
		}
		else {
			$domain_note = 'A记录到IP ' . gethostbyname($_SERVER['SERVER_NAME']);
		}

		$this->_tpl->assign('domain_note', $domain_note);
		$list = daocall('vhostinfo', 'getDomain', array(getRole('vhost')));

		if ($user['cdn']) {
			$domains = array();
			$id = 0;

			foreach ($list as $domain) {
				$dir = substr($domain['value'], 7, strlen($domain['value']));
				$dir = trim($dir, '/');
				$domain['value'] = $dir;
				$domain['id'] = $id;
				$domains[] = $domain;
				++$id;
			}

			$list = $domains;
			$this->_tpl->assign('subdir_flag', 1);
		}
		else {
			$id = 0;

			foreach ($list as $domain) {
				$domain['id'] = $id;
				$li[] = $domain;
				++$id;
			}

			$list = $li;
			$this->_tpl->assign('subdir_flag', $user['subdir_flag']);
			$this->_tpl->assign('default_subdir', $user['subdir']);
		}

		$sum = count($list);
		$this->_tpl->assign('sum', $sum);
		$this->_tpl->assign('list', $list);
		return $this->fetch('domain/show.html');
	}

	public function addForm()
	{
		$vhost = getRole('vhost');
		$this->_tpl->assign('action', 'add');
		$user = $_SESSION['user'][$vhost];
		$this->_tpl->assign('subdir_flag', $user['subdir_flag']);
		$this->_tpl->assign('default_subdir', $user['subdir']);
		return $this->_tpl->fetch('domain/add.html');
	}

	public function add()
	{
		$domain = trim($_REQUEST['domain']);
		$ddomain = '*'.substr($domain, strpos($domain,'.'));
		$vhost = getRole('vhost');
		$replace = intval($_REQUEST['replace']);
		$isreplace = false;

		if ($domain != '*') {
			$ret = daocall('vhostinfo', 'findDomain', array($domain));
			$retssss = daocall('vhostinfo', 'findDomain', array($ddomain));
			
			if ($retssss) {
				if ($retssss['vhost'] != $vhost || !$replace) {
					exit('该域名已被他人绑定，请联系管理员');
				}
			}
			
			if ($ret) {
				if ($ret['vhost'] != $vhost || !$replace) {
					exit('该域名已被他人绑定，请联系管理员');
				}
				$isreplace = true;
			}
			
		}

		$is_vhost_domain = false;

		if ($vhost_domain = daocall('setting', 'get', array('vhost_domain'))) {
			$find_vhost_domain = strstr($domain, $vhost_domain);

			if ($find_vhost_domain) {
				$is_vhost_domain = true;

				if ($domain != $vhost . '.' . $vhost_domain) {
					exit('该域名为赠送域名,不能绑定其他的二级域名');
				}
			}
		}
		
		
		

			if (!preg_match('/^[-$a-z0-9_*.]{2,512}$/i', $domain) || stripos($domain, '.') === false || $domain == '*' || $domain == '.' || substr($domain, 0, 1) == '.') {
				exit('域名不合法');
			}


		@load_conf('pub:reserv_domain');

		if (is_array($GLOBALS['reserv_domain'])) {
			$i = 0;

			while ($i < count($GLOBALS['reserv_domain'])) {
				if (strcasecmp($domain, $GLOBALS['reserv_domain'][$i]) == 0) {
					exit('该域名为保留域名,不允许绑定,请联系管理员');
				}

				++$i;
			}
		}

		$user = $_SESSION['user'][$vhost];
		if ($user['cdn'] && strncasecmp($_REQUEST['subdir'], 'http://', 7) != 0) {
			$_REQUEST['subdir'] = 'http://' . $_REQUEST['subdir'] . '/';
		}

		if ($user['domain'] == 0) {
			exit('该空间不允许绑定域名');
		}

		if (0 < $user['domain']) {
			$count = daocall('vhostinfo', 'getDomainCount', array($vhost));
			if ($count && $user['domain'] <= $count) {
				exit('该空间绑定域名数限制为:' . $user['domain'] . '个');
			}
		}

		if ($user['subdir_flag'] == 1) {
			$value = trim($_REQUEST['subdir']);

			if (!daocall('vhostinfo', 'checkDomainSubdir', array($vhost, $value, $user['max_subdir']))) {
				exit('最多绑定子目录限制为:' . $user['max_subdir']);
			}
		}
		else {
			$value = $user['subdir'];
		}

		if (!$isreplace) {
			$ret = apicall('vhost', 'addInfo', array($vhost, $domain, 0, $value));
		}
		else {
			$arr['value'] = $value;
			$ret = apicall('vhost', 'updateInfo', array($vhost, $domain, $arr));
		}

		if (!$ret) {
			exit('绑定域名失败');
		}

		if ($is_vhost_domain && !$isreplace) {
			apicall('record', 'addDnsdunRecord', array($vhost));
		}

		if (!$user['cdn']) {
			apicall('vhost', 'copyIndexForUser', array($vhost, $value));
		}

		notice_cdn_changed();
		exit('成功');
	}

	public function del()
	{
		if ($vhost_domain = daocall('setting', 'get', array('vhost_domain'))) {
			$find_vhost_domain = strstr($_REQUEST['domain'], $vhost_domain);

			if ($find_vhost_domain) {
				$vhost = getRole('vhost');
				$vhostinfo = daocall('vhost', 'getVhost', array($vhost));

				if (0 < $vhostinfo['recordid']) {
					@apicall('record', 'delDnsdunRecord', array($vhostinfo['recordid']));
				}
			}
		}

		if (!apicall('vhost', 'delInfo', array(getRole('vhost'), $_REQUEST['domain'], 0, null))) {
			exit('删除域名失败');
		}

		notice_cdn_changed();
		exit('成功');
	}
}

?>
