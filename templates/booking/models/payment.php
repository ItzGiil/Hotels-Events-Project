<?php
if(!isset($_SESSION['book']) || count($_SESSION['book']) == 0){
    header("Location: ".DOCBASE.$sys_pages['booking']['alias']);
    exit();
}

$msg_error = "";
$msg_success = "";
$field_notice = array();

$payment_arr = array_map("trim", explode(",", PAYMENT_TYPE));
if(count($payment_arr) == 1)
    $payment_type = PAYMENT_TYPE;
else
    $payment_type = (isset($_POST['payment_type'])) ? $_POST['payment_type'] : PAYMENT_TYPE;

$sendMail = ((!isset($_SESSION['book']['id']) || is_null($_SESSION['book']['id'])) && ($payment_type == "check" || $payment_type == "arrival")) ? true : false;

require(SYSBASE."templates/".TEMPLATE."/common/paypal.php");

if(($payment_type == "paypal" && isset($_POST['confirm_payment'])) || $payment_type == "check" || $payment_type == "arrival"){
    
    $amount = (ENABLE_DOWN_PAYMENT == 1 && $_SESSION['book']['down_payment'] > 0) ? $_SESSION['book']['down_payment'] : $_SESSION['book']['total'];
    
    if(!isset($_SESSION['book']['id']) || is_null($_SESSION['book']['id'])){
        
        $extra_services = array();
        foreach($_SESSION['book']['extra_services'] as $extra)
            $extra_services[] = $extra['title'].";".$extra['qty'].";".$extra['price'];

        $extra_services = implode("|", $extra_services);
                               
        $data = array();
        $data['id'] = "";
        $data['firstname'] = $_SESSION['book']['firstname'];
        $data['lastname'] = $_SESSION['book']['lastname'];
        $data['email'] = $_SESSION['book']['email'];
        $data['company'] = $_SESSION['book']['company'];
        $data['address'] = $_SESSION['book']['address'];
        $data['postcode'] = $_SESSION['book']['postcode'];
        $data['city'] = $_SESSION['book']['city'];
        $data['phone'] = $_SESSION['book']['phone'];
        $data['mobile'] = $_SESSION['book']['mobile'];
        $data['country'] = $_SESSION['book']['country'];
        $data['extra_services'] = $extra_services;
        $data['comments'] = $_SESSION['book']['comments'];
        $data['room'] = $_SESSION['book']['room'];
        $data['id_room'] = $_SESSION['book']['room_id'];
        $data['hotel'] = $_SESSION['book']['hotel'];
        $data['id_hotel'] = $_SESSION['book']['hotel_id'];
        $data['from_date'] = $_SESSION['book']['from_date'];
        $data['to_date'] = $_SESSION['book']['to_date'];
        $data['nights'] = $_SESSION['book']['nights'];
        $data['adults'] = $_SESSION['book']['adults'];
        $data['children'] = $_SESSION['book']['children'];
        $data['amount'] = number_format($_SESSION['book']['amount'], 2, ".", "");
        $data['tourist_tax'] = number_format($_SESSION['book']['tourist_tax'], 2, ".", "");
        $data['total'] = number_format($_SESSION['book']['total'], 2, ".", "");
        $data['down_payment'] = number_format($_SESSION['book']['down_payment'], 2, ".", "");
        $data['add_date'] = time();
        $data['edit_date'] = null;
        $data['status'] = 1;
        
        $_SESSION['data'] = $data;
        
        if($payment_type != "paypal"){
            
            $result_booking = db_prepareInsert($db, "pm_booking", $data);
            if($result_booking->execute() !== false)
                $_SESSION['book']['id'] = $db->lastInsertId();
            
        }else{
            $request = paypal_request($paypal_settings).
            "&METHOD=SetExpressCheckout".
            "&CANCELURL=".urlencode(getUrl()).
            "&RETURNURL=".urlencode(getUrl()).
            "&AMT=".round($amount*CURRENCY_RATE, 2).
            "&CURRENCYCODE=".CURRENCY_CODE.
            "&DESC=".urlencode($_SESSION['book']['hotel']." - ".$_SESSION['book']['room'].
            " - ".$_SESSION['book']['nights']." ".$texts['NIGHTS'].
            " - ".($_SESSION['book']['adults']+$_SESSION['book']['children'])." ".$texts['PERSONS']).
            "&LOCALECODE=".strtoupper(LANG_TAG).
            "&HDRIMG=";

            $ch = curl_init($request);
            
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSLVERSION, 6);
            
            $paypal_result = curl_exec($ch);
            if($paypal_result !== false){
                $paypal_params = get_paypal_params($paypal_result);
                
                if(isset($paypal_params['ACK']) && $paypal_params['ACK'] == "Success"){
                    $redirect_host = ($paypal_settings['test_mode'] === false) ? "paypal.com" : "sandbox.paypal.com";
                    header("Location: https://www.".$redirect_host."/webscr&cmd=_express-checkout&token=".$paypal_params['TOKEN']);
                    exit();
                }else
                    $msg_error .= "<p>Connection error with PayPal.<br>".$paypal_params['L_SHORTMESSAGE0']."<br>".$paypal_params['L_LONGMESSAGE0']."</p>";
            }else
                $msg_error .= "<p>Call error</p><p>".curl_error($ch)."</p>";
                
            curl_close($ch);
        }
    }
}

if(isset($_GET['token']) && isset($_GET['PayerID'])){
    $request = paypal_request($paypal_settings).
    "&METHOD=DoExpressCheckoutPayment".
    "&TOKEN=".htmlentities($_GET['token'], ENT_QUOTES).
    "&AMT=".round($amount*CURRENCY_RATE, 2).
    "&CURRENCYCODE=".CURRENCY_CODE.
    "&PayerID=".htmlentities($_GET['PayerID'], ENT_QUOTES).
    "&PAYMENTACTION=sale";

    $ch = curl_init($request);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);

    $paypal_result = curl_exec($ch);

    if($paypal_result !== false){
        $paypal_params = get_paypal_params($paypal_result);

        if(isset($paypal_params['ACK']) && $paypal_params['ACK'] == "Success"){

            $msg_success .= "<p class=\"text-center lead\">".$texts['PAYMENT_SUCCESS_NOTICE']."</p>";
            
            if(isset($_SESSION['data'])){
                $data = $_SESSION['data'];
                $data['status'] = 4;
                $data['payment_date'] = time();
                $data['trans'] = $paypal_params['TRANSACTIONID'];
                
                $result_booking = db_prepareInsert($db, "pm_booking", $data);
                if($result_booking->execute() !== false){
                    $_SESSION['book']['id'] = $db->lastInsertId();
                    
                    $sendMail = true;
                }
            }else
                unset($_SESSION['book']);
        }else{
            $msg_error .= "<p>Connection error with PayPal.<br>".$paypal_params['L_SHORTMESSAGE0']."<br>".$paypal_params['L_LONGMESSAGE0']."</p>";
            if(isset($_SESSION['book']['id'])) unset($_SESSION['book']['id']);
        }
    }else{
        $msg_error .= "<p>Return error</p><p>".curl_error($ch)."</p>";
        if(isset($_SESSION['book']['id'])) unset($_SESSION['book']['id']);
    }
    curl_close($ch);
}
$_SESSION['tmp_book'] = $_SESSION['book'];

if($sendMail && isset($_SESSION['book']['id'])){
    $mailContent = "
    <p><strong>".$texts['BILLING_ADDRESS']."</strong><br>
    ".$_SESSION['book']['firstname']." ".$_SESSION['book']['lastname']."<br>";
    if($_SESSION['book']['company'] != "") $mailContent .= $texts['COMPANY']." : ".$_SESSION['book']['company']."<br>";
    $mailContent .= nl2br($_SESSION['book']['address'])."<br>
    ".$_SESSION['book']['postcode']." ".$_SESSION['book']['city']."<br>
    ".$texts['PHONE']." : ".$_SESSION['book']['phone']."<br>";
    if($_SESSION['book']['mobile'] != "") $mailContent .= $texts['MOBILE']." : ".$_SESSION['book']['mobile']."<br>";
    $mailContent .= $texts['EMAIL']." : ".$_SESSION['book']['email']."</p>
    
    <p>".$texts['HOTEL']." : <strong>".$_SESSION['book']['hotel']."</strong><br>
    ".$texts['ROOM']." : <strong>".$_SESSION['book']['room']."</strong><br>
    ".$texts['CHECK_IN']." <strong>".strftime(DATE_FORMAT, $_SESSION['book']['from_date'])."</strong><br>
    ".$texts['CHECK_OUT']." <strong>".strftime(DATE_FORMAT, $_SESSION['book']['to_date'])."</strong><br>
    <strong>".$_SESSION['book']['nights']."</strong> ".$texts['NIGHTS']."<br>
    <strong>".($_SESSION['book']['adults']+$_SESSION['book']['children'])."</strong> ".$texts['PERSONS']." - 
    ".$texts['ADULTS'].": <strong>".$_SESSION['book']['adults']."</strong> / 
    ".$texts['CHILDREN'].": <strong>".$_SESSION['book']['children']."</strong><br>
    ".$texts['AMOUNT'].": ".formatPrice($_SESSION['book']['amount']*CURRENCY_RATE)." ".$texts['INCL_VAT']."</p>";

    if(!empty($_SESSION['book']['extra_services'])){
        $mailContent .= "<p><strong>".$texts['EXTRA_SERVICES']."</strong><br>";
        foreach($_SESSION['book']['extra_services'] as $i => $extra){
            $mailContent .= $extra['title']." x ".$extra['qty']." : ".formatPrice($extra['price']*CURRENCY_RATE)." ".$texts['INCL_VAT']."<br>";
        }
        $mailContent .= "</p>";
    }

    if(ENABLE_TOURIST_TAX == 1) $mailContent .= "<p>".$texts['TOURIST_TAX']." : ".formatPrice($_SESSION['book']['tourist_tax']*CURRENCY_RATE)."</p>";
    
    if($_SESSION['book']['comments'] != "") $mailContent .= "<p><b>".$texts['COMMENTS']."</b><br>".nl2br($_SESSION['book']['comments'])."</p>";
    
    $mailContent .= "<p>".$texts['TOTAL']." : <b>".formatPrice($_SESSION['book']['total']*CURRENCY_RATE)." ".$texts['INCL_VAT']."</b></p>";
    
    if(ENABLE_DOWN_PAYMENT == 1 && $_SESSION['book']['down_payment'] > 0)
        $mailContent .= "<p>".$texts['DOWN_PAYMENT']." : <b>".formatPrice($_SESSION['book']['down_payment']*CURRENCY_RATE)." ".$texts['INCL_VAT']."</b></p>";
    
    sendMail(EMAIL, OWNER, "Booking notice", $mailContent, $_SESSION['book']['email'], $_SESSION['book']['firstname']." ".$_SESSION['book']['lastname']);
    sendMail($_SESSION['book']['email'], $_SESSION['book']['firstname']." ".$_SESSION['book']['lastname'], "Booking notice", $mailContent);
    
    unset($_SESSION['book']);
}

require(SYSBASE."templates/".TEMPLATE."/common/header.php"); ?>

<section id="page">
    
    <?php include(SYSBASE."templates/".TEMPLATE."/common/page_header.php"); ?>
    
    <div id="content" class="pt30 pb30">
        <div class="container">

            <div class="alert alert-success" style="display:none;"></div>
            <div class="alert alert-danger" style="display:none;"></div>
            
            <div class="row mb30" id="booking-breadcrumb">
                <div class="col-xs-3">
                    <a href="<?php echo DOCBASE.$sys_pages['booking']['alias']; ?>">
                        <div class="breadcrumb-item done">
                            <i class="fa fa-calendar"></i>
                            <span><?php echo $sys_pages['booking']['name']; ?></span>
                        </div>
                    </a>
                </div>
                <div class="col-xs-3">
                    <a href="<?php echo DOCBASE.$sys_pages['details']['alias']; ?>">
                        <div class="breadcrumb-item done">
                            <i class="fa fa-info-circle"></i>
                            <span><?php echo $sys_pages['details']['name']; ?></span>
                        </div>
                    </a>
                </div>
                <div class="col-xs-3">
                    <a href="<?php echo DOCBASE.$sys_pages['summary']['alias']; ?>">
                        <div class="breadcrumb-item done">
                            <i class="fa fa-list"></i>
                            <span><?php echo $sys_pages['summary']['name']; ?></span>
                        </div>
                    </a>
                </div>
                <div class="col-xs-3">
                    <div class="breadcrumb-item active">
                        <i class="fa fa-credit-card"></i>
                        <span><?php echo $sys_pages['payment']['name']; ?></span>
                    </div>
                </div>
            </div>
            
            <?php echo $page['text']; ?>
            
            <?php
            if(!isset($_GET['token']) && !isset($_GET['PayerID'])){ ?>

                <form method="post" action="<?php echo DOCBASE.$sys_pages['payment']['alias']; ?>">
                
                    <p class="text-center lead pt20 pb20">
                        <?php
                        if(!isset($_POST['payment_type'])){
                            $payments = array_map("trim", explode(",", PAYMENT_TYPE));
                            if(count($payments) > 1){ ?>
                                <div class="mb10">
                                    <?php echo $texts['CHOOSE_PAYMENT']; ?>
                                </div>
                                <?php
                                foreach($payments as $payment){ ?>
                                    <button type="submit" name="payment_type" class="btn btn-default" value="<?php echo $payment; ?>">
                                        <?php
                                        switch($payment){
                                            case "paypal": ?>
                                                <i class="fa fa-paypal"></i><br>PayPal
                                                <?php
                                            break;
                                            case "check": ?>
                                                <i class="fa fa-envelope"></i><br><?php echo $texts['PAYMENT_CHECK']; ?>
                                                <?php
                                            break;
                                            case "arrival": ?>
                                                <i class="fa fa-building"></i><br><?php echo $texts['PAYMENT_ON_ARRIVAL']; ?>
                                                <?php
                                            break;
                                        } ?>
                                    </button>
                                    <?php
                                }
                            }
                        }
                        
                        if($payment_type == "paypal"){
                            echo $texts['PAYMENT_PAYPAL_NOTICE']; ?><br>
                            <img src="<?php echo DOCBASE."templates/".TEMPLATE."/images/paypal-cards.jpg"; ?>" alt="PayPal" class="img-responsive mt10 mb30">
                            <?php
                        }
                        if($payment_type == "check") echo str_replace("{amount}", "<b>".formatPrice($amount*CURRENCY_RATE)." ".$texts['INCL_VAT']."</b>", $texts['PAYMENT_CHECK_NOTICE']);
                        
                        if($payment_type == "arrival") echo str_replace("{amount}", "<b>".formatPrice($_SESSION['tmp_book']['total'])." ".$texts['INCL_VAT']."</b>", $texts['PAYMENT_ARRIVAL_NOTICE']); ?>
                    </p>
                        
                    <div class="clearfix"></div>
                    <a class="btn btn-default btn-lg pull-left" href="<?php echo DOCBASE.$sys_pages['summary']['alias']; ?>"><i class="fa fa-angle-left"></i> <?php echo $texts['PREVIOUS_STEP']; ?></a>
                    <?php
                    if($payment_type == "paypal"){ ?>
                        <button type="submit" name="confirm_payment" class="btn btn-primary btn-lg pull-right"><i class="fa fa-credit-card"></i> <?php echo $texts['PAY']; ?></button>
                        <?php
                    } ?>
                </form>
                <?php
            }elseif(isset($_GET['PayerID'])){ ?>
                <script>
                    $(function(){
                        setTimeout(function(){
                            $(location).attr('href', '<?php echo DOCBASE.$sys_pages['booking']['alias']; ?>');
                        }, 4000);
                    });
                </script>
                <?php
            }else{ ?>
                <p class="text-center">
                    <?php echo $texts['PAYMENT_CANCEL_NOTICE']; ?><br>
                </p>
                <?php
            } ?>
        </div>
    </div>
</section>
