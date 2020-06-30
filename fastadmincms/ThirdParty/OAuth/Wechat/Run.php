<?php
/**
 * 执行程序
 */

// 微信插件的扫码动作
if (!dr_is_app('weixin')) {
    $this->_msg(0, dr_lang('没有安装微信应用插件'));
}
\Phpcmf\Service::C()->init_file('weixin');
$rt = \Phpcmf\Service::M('user', 'weixin')->qrcode_bang($this->member);
if (!$rt['code']) {
    $this->_msg(0, $rt['msg']);
} else {
    // 获取返回页面
    $url = \Phpcmf\Service::L('Security')->xss_clean($back ? urldecode($back) : $_SERVER['HTTP_REFERER']);
    if (!$url || strpos($url, 'login') !== false ) {
        $url = $this->uid ? dr_member_url('account/oauth') : dr_member_url('home/index');
    }
    // 是否在公众号内部
    if (dr_is_weixin_app()) {
        # 公众号内部请求登录

        if ($action == 'callback') {

            $code = \Phpcmf\Service::L('input')->get('code');
            $state = \Phpcmf\Service::L('input')->get('state');

            $url = 'https://api.weixin.qq.com/sns/oauth2/access_token?appid='.$this->weixin['account']['appid'].'&secret='.$this->weixin['account']['appsecret'].'&code='.$code.'&state='.$state.'&grant_type=authorization_code';
            $rt = wx_get_https_json_data($url);
            if (!$rt['code']) {
                $this->_msg(0, $rt['msg']);
            }

            $user = \Phpcmf\Service::M()->table(weixin_wxtable('user'))->where('openid', $rt['data']['openid'])->getRow();
            if (!$user) {
                // 刷新
                $rs = wx_get_https_json_data('https://api.weixin.qq.com/sns/oauth2/refresh_token?appid='.$this->weixin['account']['appid'].'&grant_type=refresh_token&refresh_token='.$rt['data']['refresh_token']);
                if (!$rs['code']) {
                    $this->_msg(0, $rs['msg']);
                }

                $rts = wx_get_https_json_data('https://api.weixin.qq.com/sns/userinfo?access_token='.$rs['data']['access_token'].'&openid='.$rt['data']['openid'].'&lang=zh_CN');
                if (!$rts['code']) {
                    $this->_msg(0, $rts['msg']);
                } elseif (!$rts['data']['nickname']) {
                    $this->_msg(0, '未获取到微信用户昵称');
                }
                $user = $rts['data'];
                $user['id'] = \Phpcmf\Service::M('user', 'weixin')->insert_user($rts['data']);
            }

            $oid = $rt['data']['openid'];
            $rt = \Phpcmf\Service::M('member')->insert_oauth($this->uid, 'login', [
                'oid' => $oid,
                'oauth' => 'wechat',
                'avatar' => $user['headimgurl'],
                'unionid' => (string)$user['unionid'],
                'nickname' => dr_emoji2html($user['nickname']),
                'expire_at' => SYS_TIME,
                'access_token' => 0,
                'refresh_token' => 0,
            ], $state);
            if (!$rt['code']) {
                $this->_msg(0, $rt['msg']);
                exit;
            } else {
                if ($user['uid'] && $state) {
                    // 存储cookie
                    $member = \Phpcmf\Service::M('member')->member_info($user['uid']);
                    if (!$member) {
                        \Phpcmf\Service::M()->db->table('weixin_user')->where('uid', $user['uid'])->delete();
                        dr_redirect($rt['msg']);
                        exit;
                    }
                    \Phpcmf\Service::M('member')->save_cookie($member, 1);
                    $goto_url = urldecode($state);
                    if (strpos($state, DOMAIN_NAME) === false) {
                        // 域名不同的情况下
                        $rt = \Phpcmf\Service::M('member')->sso($member, 1);
                        $sso = '';
                        foreach ($rt as $url) {
                            $sso.= '<script src="'.$url.'"></script>';
                        }
                        $this->_msg(1, dr_lang('%s，欢迎回来', dr_html2emoji($user['nickname'])).$sso, $goto_url, 0);exit;
                    }
                    dr_redirect($goto_url);
                } else {
                    dr_redirect($rt['msg']);
                }
            }
        } else {
            dr_redirect('https://open.weixin.qq.com/connect/oauth2/authorize?appid='.$this->weixin['account']['appid'].'&redirect_uri='.urlencode($callback_url).'&response_type=code&scope=snsapi_userinfo&state='.urlencode($url).'#wechat_redirect');
        }

    } else {
        $notify_url = '/index.php?s=weixin&c=member&m=wxbang&ep='.$rt['data']['action_info']['scene']['scene_str'];
        if (strpos($url, 'is_admin_call')) {
            // 后台会话
            $notify_url.= '&is_admin_call='.urlencode($url);
        }

        \Phpcmf\Service::V()->admin();
        \Phpcmf\Service::V()->assign([
            'back_url' => $url,
            'qrcode_url' => $rt['msg'],
            'notify_url' => $notify_url,
        ]);
        \Phpcmf\Service::V()->display('api_weixin_bang.html');
    }
}
// 跳转微信公众号