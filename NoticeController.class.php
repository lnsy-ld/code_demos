<?php
/**
 * 小程序消息
 */

namespace Tuan\Controller\Cron;

use Think\Api;
use Think\Log\LogHelper;
use Chunbo\BaseController;
use Chunbo\Cache\SmartCache;
use Tuan\Model\TuanActivityModel;
use Tuan\Model\TuanOrderModel;
use Tuan\Model\TuanOrderDetailModel;

class NoticeController extends BaseController
{

    private $cachekeyAccessToken = 'tuan:qrcode_access_token';
    private $wxTemplateUrl = 'https://api.weixin.qq.com/cgi-bin/wxopen/template/list?access_token=%s';
    private $wxAccessTokenUrl = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=%s&secret=%s';
    private $wxappSendTemplateMessageUrl = 'https://api.weixin.qq.com/cgi-bin/message/wxopen/template/send?access_token=%s';

    const WXAPP_TUAN_SUCCESS_MESSAGE_TEMPLATE_ID = 'wst3OAbcw01n1eDqlJhAXuGzmSCEss1cmMVYUOBX9-M';
    const WXAPP_TUAN_STATUS_MESSAGE_TEMPLATE_ID = 'UyW-oGDE9qzQBsSzvCKmu05SjNh-RdOL5WKcJ0Jah40';
    const SEND_MESSAGE_LOG_KEY = 'TUAN_SEND_TEMPLATE_MESSAGE_LOG';
    const COMING_TO_AN_END_NOTICE_TIME_BY_HOURE = 24;// 小时

    public function __construct()
    {
        header('Content-type:text/html;charset=utf-8');
    }

    // 拼团成功提醒
    public function sendSuccessMsg()
    {
        $list = $this->getSuccessOrders();
        if (!$list) {
            exit(json_encode('', JSON_UNESCAPED_UNICODE));
        }
        $activity_model = new TuanActivityModel();
        $notice_model = D('TuanNotice');

        foreach ($list as $row) {
            $activityInfo = $activity_model->getByPK($row['activity_id']);
            $delivery_data = substr($row['delivery_time'], 0, 4).'年';
            $delivery_data .= substr($row['delivery_time'], 5, 2).'月';
            $delivery_data .= substr($row['delivery_time'], 8, 2).'日';
            $notice = $notice_model->getNoticeList(['touser' => $row['wxapp_open_id']])[0];
            if ($notice['notice_resource'] <= 0) {
                continue;
            }
            $data = array(
                'keyword1' => ['value' => $activityInfo['product_name'].'测试'],
                'keyword2' => ['value' => $activityInfo['sell_price']],
                'keyword3' => ['value' => $activityInfo['activity_price']],
                'keyword4' => ['value' => '拼团成功啦，电子券可在微信-我-卡包-我的票券中查看喔！'],
                'keyword5' => ['value' => $delivery_data],
                'keyword6' => ['value' => '点击查看拼团详情'],
            );
            $this->sendMsg(
                    $row['wxapp_open_id'], self::WXAPP_TUAN_SUCCESS_MESSAGE_TEMPLATE_ID, $notice['form_id'], $data, 'pages/myJoinDetail/myJoinDetail?order_id='.$row['id']
            );
            $notice_model->consumeResource($notice['id']);
        }
    }

    // 拼团进度提醒(用于提醒即将到期而没有成团的用户)
    public function sendStatusMsg()
    {
        $order_list = $this->getComingEndOrders();
        if (!$order_list) {
            exit(json_encode('', JSON_UNESCAPED_UNICODE));
        }
        $order_data = array_column($order_list, null, 'id');
        $order_ids = array_column($order_list, 'id');
        $activity_model = new TuanActivityModel();
        $notice_model = D('TuanNotice');
        $list = $this->getComingEndOrderDetails($order_ids);
        $sendList = [];

        foreach ($list as $row) {
            $activityInfo = $activity_model->getByPK($row['activity_id']);
            $tuan_time = substr($row['pay_time'], 0, 4).'年';
            $tuan_time .= substr($row['pay_time'], 5, 2).'月';
            $tuan_time .= substr($row['pay_time'], 8, 2).'日';
            $notice = $notice_model->getNoticeList(['touser' => $row['wxapp_open_id']])[0];
            if ($notice['notice_resource'] <= 0) {
                continue;
            }
            $order_info = $order_data[$row['tuan_order_id']];
            $data = array(
                'keyword1' => ['value' => $activityInfo['product_name'].'测试'],
                'keyword2' => ['value' => '已有'.$order_info['joiners'].'人参与'],
                'keyword3' => ['value' => '还剩'.self::COMING_TO_AN_END_NOTICE_TIME_BY_HOURE.'小时'],
                'keyword4' => ['value' => $row['member_nickname'].',留给你的时间不多了，赶快拉人来拼团吧！'],
                'keyword5' => ['value' => $tuan_time],
                'keyword6' => ['value' => '拼团中'].'pages/myJoinDetail/myJoinDetail?tuan_order_detail_id=10052827',
                'keyword7' => ['value' => $activityInfo['min_joiners'].'人'],
                'keyword8' => ['value' => $activityInfo['activity_price']],
                'keyword9' => ['value' => $order_info['end_time']],
            );
            $sendList[$row['id']] = $this->sendMsg(
                    $row['wxapp_open_id'], self::WXAPP_TUAN_STATUS_MESSAGE_TEMPLATE_ID, $notice['form_id'], $data, 'pages/order/detail?id=10034391'
            );
            $notice_model->consumeResource($notice['id']);
        }
        exit(json_encode($sendList, JSON_UNESCAPED_UNICODE));
    }

    // 预约提醒
    public function sendSubscribeMsg()
    {
        
    }

    private function getComingEndOrders()
    {
        $model = D('TuanOrder');
        $start_time = date('Y-m-d H:i:s', time() + self::COMING_TO_AN_END_NOTICE_TIME_BY_HOURE * 3600 - 300);
        $end_time = date('Y-m-d H:i:s', time() + self::COMING_TO_AN_END_NOTICE_TIME_BY_HOURE * 3600);
        $where['end_time'] = array('between', [$start_time, $end_time]);
        $where['status'] = TuanOrderModel::TUAN_ORDER_STATUS_CONFIRMED;
        return $model->getOrderList($where, 0, PHP_INT_MAX);
    }

    private function getComingEndOrderDetails($order_ids)
    {
        $model = D('TuanOrderDetail');
        $where['tuan_order_id'] = array('in', $order_ids);
        $where['status'] = TuanOrderDetailModel::TUAN_ORDER_DETAIL_STATUS_IN_PROGRESS;
        return $model->lists($where, 0, PHP_INT_MAX);
    }

    private function getSuccessOrders()
    {
        $model = D('TuanOrderDetail');
        $start_time = date('Y-m-d H:i:s', time() - 300);
        $end_time = date('Y-m-d H:i:s', time() - 0);
        $where['order_time'] = array('between', [$start_time, $end_time]);
        $where['status'] = TuanOrderDetailModel::TUAN_ORDER_DETAIL_STATUS_SUCCESS;
        return $model->lists($where, 0, PHP_INT_MAX);
    }

    public function getTemplateList()
    {
        $token = $this->getAccessToken();
        $wxTemplateUrl = sprintf($this->wxTemplateUrl, $token);
        $param['offset'] = 0;
        $param['count'] = 20;
        $result = $this->requestApi($wxTemplateUrl, json_encode($param));
        if ($result) {
            $resultData = json_decode($result, true);
            exit(json_encode($resultData['list'], JSON_UNESCAPED_UNICODE));
        }
    }

    public function sendMsg($touser, $template_id, $form_id, $data, $page = '', $emphasis_keyword = '')
    {
        $token = $this->getAccessToken();
        $url = sprintf($this->wxappSendTemplateMessageUrl, $token);
        $msg = [];
        $msg['touser'] = $touser;
        $msg['template_id'] = $template_id;
        $msg['form_id'] = $form_id;
        $msg['page'] = $page;
        $msg['data'] = $data;
        $msg['emphasis_keyword'] = $emphasis_keyword ? $emphasis_keyword.'.DATA' : '';
        $rtn['result'] = $this->requestApi($url, json_encode($msg));
        $rtn['data'] = $msg;
        $this->log($rtn, $template_id);
        return $rtn;
    }

    private function requestApi($url, $data)
    {
        $ch = curl_init();
        $header = "Accept-Charset: utf-8";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MSIE 5.01; Windows NT 5.0)');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $tmpInfo = curl_exec($ch);
        if (curl_errno($ch)) {
            return false;
        } else {
            return $tmpInfo;
        }
    }

    private function getAccessToken()
    {
        $cache = $this->cache();
        $cacheKey = $this->cachekeyAccessToken;
        $token = $cache->get($cacheKey);

        if (is_string($token) && strlen($token) > 0) {
            return $token;
        }

        $accessTokenUrl = sprintf($this->wxAccessTokenUrl, C('WX_APPID'), C('WX_SECRET'));
        $rep = Api::request($accessTokenUrl, null, 'get');

        if ($rep || strlen($rep['access_token']) <= 0) {
            $accessToken = $rep['access_token'];
            $expiresIn = $rep['expires_in'] - 120;

            $cache->set($cacheKey, $accessToken, $expiresIn);
            return $accessToken;
        } else {
            $ret = C('TUAN_CODE2_ACCESS_TOKEN_ERROR');
            exit(json_encode($ret, JSON_UNESCAPED_UNICODE));
        }
    }

    private function cache()
    {
        $cache = SmartCache::getInstance(SmartCache::CACHE_POOL_API);
        return $cache;
    }

    private function log($message, $template_id)
    {
        $log_message = self::SEND_MESSAGE_LOG_KEY.'|'.$template_id.'|'.json_encode($message, JSON_UNESCAPED_UNICODE);
        LogHelper::info($log_message);
    }
}
