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
			$domain_note = '.' . str_replace('*',$user['name'],$cname_host);
		}
		else {
			$domain_note = 'A记录到IP：' . gethostbyname($_SERVER['SERVER_NAME']);
		}

		$this->_tpl->assign('domain_note', $domain_note);
		$list = daocall('vhostinfo', 'getDomain', array($vhost));

		if ($user['cdn']) {
			$domains = array();
			$id = 0;

			foreach ($list as $domain) {
				$isSsl = 0;
				$proto = null;
				if(substr($domain['value'], 0, 9) == 'server://'){
					$start = strpos($domain['value'],'nodes=')+6;
					$end = strpos($domain['value'],':',$start);
					$end2 = strpos($domain['value'],':',$end+1);
					$dir = substr($domain['value'], $start, $end - $start);
					$port = substr($domain['value'], $end+1, $end2 - $end - 1);
					if($port != '443s' && $port != '80'){
						$dir .= ':' . $port;
					}
					if(strpos($domain['value'], 'proto=tcp')){
						$proto = 'tcp';
					}elseif(strpos($domain['value'], 'proto=https')){
						$proto = 'https';
					}elseif(strpos($domain['value'], '|') && strpos($domain['value'], ':443s:')){
						$proto = 'follow';
					}
					if(strpos($domain['value'],';') && strpos($domain['value'],'.crt') && strpos($domain['value'],'.key')){
						$isSsl = 1;
					}
				}else{
					$dir = substr($domain['value'], 7, strlen($domain['value']));
					$dir = trim($dir, '/');
				}
				$domain['value'] = $dir;
				$domain['id'] = $id;
				$domain['ssl'] = $isSsl;
				$domain['proto'] = $proto;
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

		if (strpos($user['port'], 's')!==false) {
			$this->_tpl->assign('ssl', 1);
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

	private function get_host($domain){
		$data = explode('.', $domain);
		$co_ta = count($data);
		//判断是否是双后缀
		$zi_tow = true;
		$host_cn = '.com.cn,.net.cn,.org.cn,.gov.cn,.cn.com,.ac.cn';
		$host_cn = explode(',', $host_cn);
		foreach($host_cn as $host){
			if(strpos($domain,$host)){
				$zi_tow = false;
			}
		}
		$host = null;
		//如果是返回FALSE ，如果不是返回true
		if($zi_tow == true){
			if(!empty($data[$co_ta-2]) && !empty($data[$co_ta-1]))
				$host = $data[$co_ta-2].'.'.$data[$co_ta-1];
		}else{
			if(!empty($data[$co_ta-3]) && !empty($data[$co_ta-2]) && !empty($data[$co_ta-1]))
				$host = $data[$co_ta-3].'.'.$data[$co_ta-2].'.'.$data[$co_ta-1];
		}
		return $host;
	}

	public function info()
	{
		$vhost = getRole('vhost');
		$user = $_SESSION['user'][$vhost];
		$domain = strtolower(trim($_REQUEST['domain']));
		$ret = daocall('vhostinfo', 'getInfo', array($vhost, 0, $domain));
		if($ret[0]){
			$result['code'] = 0;
			$value = $ret[0]['value'];
			if ($user['cdn']) {
				$result['cdn'] = 1;
				$proto = 'http';
				if(substr($value, 0, 9) == 'server://'){
					$start = strpos($value,'nodes=')+6;
					$end = strpos($value,':',$start);
					$end2 = strpos($value,':',$end+1);
					$dir = substr($value, $start, $end - $start);
					$port = substr($value, $end+1, $end2 - $end - 1);
					if($port != '443s' && $port != '80'){
						$dir .= ':' . $port;
					}
					if(strpos($value, 'proto=tcp')){
						if(!getRole('admin')){
							exit(json_encode(array('code'=>-1, 'msg'=>'tcp回源协议只能由管理员修改')));
						}
						$proto = 'tcp';
					}elseif(strpos($value, 'proto=https')){
						$proto = 'https';
					}elseif(strpos($value, '|') && strpos($value, ':443s:')){
						$proto = 'follow';
					}
				}else{
					$dir = substr($value, 7, strlen($value));
					$dir = trim($dir, '/');
				}
				$result['proto'] = $proto;
				$result['subdir'] = $dir;
			}else{
				$result['cdn'] = 0;
				$result['subdir'] = $value;
			}
			exit(json_encode($result));
		}else{
			exit(json_encode(array('code'=>-1, 'msg'=>'该域名不存在')));
		}
	}

	public function add()
	{
		$domain = strtolower(trim($_REQUEST['domain']));
		$subdir = trim($_REQUEST['subdir']);
		$proto = trim($_REQUEST['proto']);
		$vhost = getRole('vhost');
		$replace = intval($_REQUEST['replace']);
		$isreplace = false;
		if(!$domain)exit('域名不能为空');
		$domain_bind = daocall('setting', 'get', array('domain_bind'));

		if ($domain != '*') {
			$ret = daocall('vhostinfo', 'findDomain', array($domain));

			if ($ret) {
				if (($ret['vhost'] != $vhost)) {
					$vhostinfo = daocall('vhost', 'getVhost', array($ret['vhost']));
					if($vhostinfo['status']==1){
						//如果是已暂停的用户，可以强制取消绑定域名
						if (!apicall('vhost', 'delInfo', array($ret['vhost'], $_REQUEST['domain'], 0, null))) {
							exit('该域名已被他人绑定，请联系管理员(del)');
						}
						$isreplace = false;
					}else{
						exit('该域名已被他人绑定，请联系管理员');
					}
				}else{
					$isreplace = true;
				}
			}
			
			//当绑定xx.www.com的时候判断*.www.com是否被别的用户绑定
			if(strpos($domain, '.')!=strrpos($domain, '.') && substr($domain, 0 ,1)!='*' && !getRole('admin')){
				$domain2 = str_replace(substr($domain,0,strpos($domain, '.')),'*',$domain);
				$ret = daocall('vhostinfo', 'findDomain', array($domain2));

				if ($ret) {
					if (($ret['vhost'] != $vhost)) {
						$vhostinfo = daocall('vhost', 'getVhost', array($ret['vhost']));
						if($vhostinfo['status']==1){
							if (!apicall('vhost', 'delInfo', array($ret['vhost'], $_REQUEST['domain'], 0, null))) {
								exit('该域名已被他人绑定，请联系管理员(del)');
							}
							$isreplace = false;
						}else{
							exit('该域名已被他人绑定，请联系管理员');
						}
					}
				}
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

		if($domain_bind == 1){
			$maindomain = $this->get_host($domain);
			if ($domain == '*' || $domain == '*.' || $maindomain==null || strpos($maindomain,'*')!==false) {
				if (!getRole('admin')) {
					exit('域名不合法');
				}
			}elseif (!preg_match('/^[-$a-z0-9_*.]{2,512}$/i', $domain) || (stripos($domain, '.') === false) || substr($domain, -1) == '.' || substr($domain, 0 ,1) == '.' || substr($domain, 0 ,1) == '*' && substr($domain, 1 ,1) != '.' || substr_count($domain, '*')>1) {
				exit('域名不合法');
			}
		}else{
			if ($domain == '*' || strpos($domain, '*') !== false) {
				if (!getRole('admin')) {
					exit('域名不合法,泛绑定只能由管理员添加');
				}
			}elseif (!preg_match('/^[-$a-z0-9_*.]{2,512}$/i', $domain) || (stripos($domain, '.') === false) || substr($domain, -1) == '.' || substr($domain, 0 ,1) == '.' || substr($domain, 0 ,1) == '*' && substr($domain, 1 ,1) != '.') {
				exit('域名不合法');
			}
		}

		if(!$isreplace && $proto=='tcp' && !getRole('admin')){
			exit('tcp回源协议只能由管理员添加');
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
		if ($user['cdn'] && strncasecmp($subdir, 'http://', 7) != 0 && strncasecmp($subdir, 'https://', 8) != 0 && strncasecmp($subdir, 'server://', 9) != 0) {
			if(strpos($subdir,':')!==false){
				$port = substr($subdir, strpos($subdir,':')+1);
				$subdir = substr($subdir, 0, strpos($subdir,':'));
			}else{
				$port = '80';
			}
			if($this->checkIp($subdir)==false && strpos($subdir,'.')==false){
				exit('源站IP填写错误');
			}
			if(!is_numeric($port) || $port<0 || $port>65535){
				exit('源站端口填写错误');
			}
			if($proto == 'tcp'){
				$subdir = 'server://nodes=' . $subdir . ':' . $port . ':0:1/proto=tcp/error_count=1/error_try_time=30';
			}elseif($proto == 'https'){
				$subdir = 'server://proto=https/nodes=' . $subdir . ':443s:0:1';
			}elseif($proto == 'follow'){
				$subdir = 'server://nodes=' . $subdir . ':' . $port . ':0:1|/nodes=' . $subdir . ':443s:0:1';
			}else{
				$subdir = 'server://nodes=' . $subdir . ':' . $port . ':0:1';
			}
			if($isreplace){
				$ret = daocall('vhostinfo', 'getInfo', array($vhost, 0, $domain));
				if($ret[0]){
					if (strncasecmp($ret[0]['value'], 'server://', 9) == 0 && strpos($ret[0]['value'], ';' . $domain . '.crt|' . $domain . '.key')!==false){
						$subdir .= ';' . $domain . '.crt|' . $domain . '.key';
					}
				}
			}
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

		if ($user['cdn']){
		}
		elseif ($user['subdir_flag'] == 1) {
			if (!daocall('vhostinfo', 'checkDomainSubdir', array($vhost, $subdir, $user['max_subdir']))) {
				exit('最多绑定子目录限制为:' . $user['max_subdir']);
			}
		}
		else {
			$subdir = $user['subdir'];
		}

		if (!$isreplace) {
			$ret = apicall('vhost', 'addInfo', array($vhost, $domain, 0, $subdir));
		}
		else {
			$arr['value'] = $subdir;
			$ret = apicall('vhost', 'updateInfo', array($vhost, $domain, $arr));
		}

		if (!$ret) {
			exit('绑定域名失败');
		}

		if ($is_vhost_domain && !$isreplace) {
			apicall('record', 'addDnsdunRecord', array($vhost));
		}

		if (!$user['cdn']) {
			apicall('vhost', 'copyIndexForUser', array($vhost, $subdir));
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

	private function checkIp($ip)
	{
		if (!filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			return false;
		}

		return true;
	}
}

?>