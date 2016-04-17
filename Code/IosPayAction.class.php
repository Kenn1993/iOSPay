<?php
/**
 * iOS支付类
 */
class IosPayAction extends CommonAction{
   /*
     * 苹果内购返回订单号
     * param:string encryptId 加密用户ID *
     * param:string money 充值金额 *
     * return:string  订单号
     */
    public function iosPay(){
 		//接收参数
        $encryptId = $_REQUEST['encryptId'];
        $money = $_REQUEST['money'];
        $des = new DES();
        //解密用户ID
        $uid = intval($des->decrypt($encryptId));

        $postData = array();
        $postData['user_id'] = $uid;
        $postData['pay_money'] = $money;
        $postData['pay_type'] = 'ios';
        //入库生成订单
        $rechargeSDK = SDK::getSDK('Recharge');
        $add_re = $rechargeSDK->getFunction('addOrder',$postData);
        if($add_re && $add_re['status']){
      		//处理订单号
			$order_id = $this->dealOrder($add_re);
			//返回结果
			$result['status'] = 1;
			$result['data'] = $order_id;
			echo json_encode($result);exit;
        }else{
            $result['status'] = 0;
			$result['data'] = '订单生成失败';
			echo json_encode($result);exit;
        }
    }

	/*
     * 服务器二次验证并修改订单状态(接收客户端支付完成后的数据进行验证)
     * param:string transactionReceipt 支付凭证
     * param:string order_id 订单号
     * return:string
     */
    public function checkVerify(){
		//接收参数
        $verify = $_REQUEST['transactionReceipt'];
        $order_id = $_REQUEST['order_id'];
		//解密凭证
        $temp = preg_replace("/[^0-9a-fA-F]/","", $verify);
        $ascii = '';
        for($i = 0; $i < strlen($temp); $i = $i + 2) {
            $ascii = $ascii.chr(hexdec(substr($temp, $i, 2)));
        }
        //再进行base64加密
        $postData = json_encode(
            array('receipt-data' =>base64_encode($ascii))
        );

        //curl调用苹果官方接口进行二次验证

		//沙盒测试地址
        $endpoint = 'https://sandbox.itunes.apple.com/verifyReceipt';
		//正式地址
        //$endpoint = 'https://buy.itunes.apple.com/verifyReceipt';

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);  //这两行一定要加，不加会报SSL 错误
        curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        curl_close($ch);
		
        $data = json_decode($response,true);
		//判断苹果接口返回的结果，如果status为0则验证成功，如果为20010等错误码则验证失败
        if($info['status'] == 0){
            //进行修改订单状态和到账金额等操作
			$result['status']=1;
        }else{
            //进行修改订单状态为失败，不用到账金额
			$result['status']=0;
        }
		//返回验证状态给iOS端，让iOS端提示用户支付结果或进行退款操作
        echo json_encode($result);exit;
    }
}

?>