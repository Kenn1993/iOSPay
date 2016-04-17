# iOS内购支付PHP后端接口的实现
[![Support](https://img.shields.io/badge/support-PHP-blue.svg?style=flat)](http://www.php.net/)
[![Support](https://img.shields.io/badge/support-ThinkPHP-red.svg?style=flat)](http://www.thinkphp.cn/)

## 原理介绍
最近接到了一个接入iOS内购支付的需求，为iOS端提供接口，在这里我就记录和分享一下我的实现过程。这里需要提供2个接口给iOS端，第一个接口是后端生成内部订单号返回给iOS端。而第二个接口则是，iOS端完成支付后把
数据返回到后端，后端调用苹果提供的接口进行验证并修改内部订单状态及金额到账操作。

## 实现流程
### 1、后端生成内部订单号返回给iOS端，iOS端获取该订单号调起支付界面	
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
### 2、iOS端支付完成后，把内部订单号和苹果返回的交易凭证传到后端进行二次验证，并修改内部订单状态，到账金额等。
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

其实iOS内购支付的原理和支付宝，微信支付的原理差不多，但值得注意的是，第二个接口里iOS端传来的支付凭证一定需要解密再加密之后才能进行调用苹果接口，而且苹果返回的数据只有交易时间、苹果订单号等很少的信息，并不会返回内部订单号和充值金额等信息，这就需要iOS进行传参才能进行后端数据库订单状态的修改。

