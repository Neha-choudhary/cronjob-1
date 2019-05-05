<?php

namespace App\Console\Commands\Remind;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Card extends Remind
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remind:couponcard';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '卡券到期提醒';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!$this->redis || !$this->confRedis) return false;
        DB::enableQueryLog();
        // $phones=$this->redis->sMembers('');
        $rows = DB::table('coupon_card')->select(DB::raw("COUNT(id) AS num,TIMESTAMPDIFF(DAY,NOW(),FROM_UNIXTIME(expire_time,'%Y-%m-%d')) AS expire_day,FROM_UNIXTIME(expire_time,'%Y-%m-%d') AS expired,phone,cid"))->whereRaw("TIMESTAMPDIFF(DAY,NOW(),FROM_UNIXTIME(expire_time,'%Y-%m-%d %H:%I:%S')) IN(30,15,7,3,1)")->where('remind_at',date('Y-m-d'))->groupBy('cid', 'phone')->get();
        Log::info('sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        if ($rows->isEmpty()) return false;
        $rows = $rows->toArray();
        $this->redis->multi();
        array_walk($rows, function ($row, $index) {
            $checkRedisMember = $this->redis->sIsMember('remind:couponcard:' . $row['cid'].':'.date('Ymd'));
            if ($checkRedisMember) return false;
            $sAddRedis = $this->redis->sAdd('remind:couponcard:' . $row['cid'].':'.date('Ymd'), $row['phone']);
            if (!$sAddRedis) exit(); // 添加到集合失败
            $cron = $this->redis->hGet('cron_config', 'company:' . $row['cid']);
            // 获取推送时间类型
            $step = explode(',', $cron['jobExpire']['expire_step']);
            $days = $row['expire_day'];
            if (!$cron || strtotime($cron['jobExpire']['start_time']) > time() || strtotime($cron['jobExpire']['end_time']) < time() || $cron['status'] <= 0 || ($step && !in_array($days, $step)) || ($cron['jobExpire']['start_at'] && date('H:i:s') < $cron['jobExpire']['start_at']) || ($cron['jobExpire']['end_at'] && date('H:i:s') > $cron['jobExpire']['end_at'])) {
                // 获取不到“证件提醒”的推送设置；开始、结束时间不符合设置要求；已禁用；日期时间不符合推送设置要求；当前不符合推送时间设置要求
                Log::info('不符合推送设置要求: ' . $row['cid']);
                return false;
            }
            $storeInfo = '';
            $store = null;
            if ($row['comno']) {
                $store = $this->db->table('store')->where('comno', $row['ComNo'])->where('cid', $row['cid'])->first();
                if ($store) {
                    // $data['store_id'] = $store->id;
                    // $data['store_name'] = $store->branch;
                    if ($store->tel) $storeInfo .= " 【" . $store->branch . "】 " . $store->tel;
                }
            }
            $pushType = explode(',', $cron['push_type']);
            $this->redis->sAdd('remind:couponcard:'.$row['cid'].':' . date('Ymd'), $row['phone']);
            $user = $this->mydb->table('member_openid')->where(['cid' => $row['cid'], 'phone' => $row['phone']])->first();
            $tpl = $this->confRedis->hGet('wechat_template:' . $row['cid'], 'service_expire_notice');
            if ((!$pushType || in_array('wechat', $pushType)) && $user && $tpl) {
                $url = config('app.url') . '/user/coupon/couponList/amcc/' . $row['cid'];
                $customer = '尊敬的';
                $customer .= $row['registerno'] ? $row['registerno'] . '车主' : '客户';
                $num = $row['num'];
                $msg = array(
                    'touser' => $user['openid'],
                    'template_id' => $tpl,
                    'url' => $url,
                    'data' => array(
                        'first' => array('value' => "{$customer}，您有{$num}张优惠券即将到期，请尽快使用哦！", 'color' => ''),
                        'keyword1' => array('value' => '优惠券', 'color' => ''),
                        'keyword2' => array('value' => $row['expired'], 'color' => '',),
                        'remark' => array('value' => "\n感谢选择我们的服务！\n" . $storeInfo, 'color' => '')
                    )
                );
               
                $this->jobData[]=[
                    'cid' => $row['cid'],
                    'comno' => $row['comno']?:'A00',
                    'property' => '卡券到期提醒',
                    'type' => 'wechat',
                    'action' => 'push',
                    'from_id' => $row['id'],
                    'function_code' => '',
                    'relation_code' => '',
                    'job' => json_encode($msg, JSON_UNESCAPED_UNICODE),
                    'fail_content' => '',
                    'create_at' => Carbon::now()->format('Y-m-d H:i:s'),
                ];
            }
        });
        $expireTime = date("Y-m-d", strtotime("+1 day"));
        $this->redis->expireAt("remind:couponcard:".date('Ymd'), $expireTime);
    }
}
