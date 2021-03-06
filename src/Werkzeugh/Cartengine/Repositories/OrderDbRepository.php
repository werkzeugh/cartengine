<?php namespace Werkzeugh\Cartengine\Repositories;


use App;

class OrderDbRepository implements \Werkzeugh\Cartengine\Interfaces\OrderRepositoryInterface {


  function getModel()
  {

        $model=\App::make('Werkzeugh\Cartengine\Interfaces\OrderInterface');

        return $model;
  }

  public function getOrderAsArray($rec)
  {

    $arr=$rec->getAttributes();

    $arr['items']=json_decode($arr['items_json'],1);
    unset($arr['items_json']);
    // unset($arr['items_json']);

    return $arr;
  }

  function getOrderByOrderNr($ordernr)
  {

    $res=$this->getOrderAsModelByOrderNr($ordernr);
    if($res)
      return $this->getOrderAsArray($res);
    else
      return NULL;

  }


  public function getOrderAsModelByOrderNr($ordernr)
  {

    $model=\App::make('Werkzeugh\Cartengine\Interfaces\OrderInterface');
    $res=$model->where('order_nr','=',$ordernr)->first();

    return $res;

  }


  function getOrderByTransactionId($transaction_id)
  {

    $res=$this->getOrderAsModelByTransactionId($transaction_id);
    if($res)
      return $this->getOrderAsArray($res);
    else
      return NULL;

  }


  public function getOrderAsModelByTransactionId($transaction_id)
  {


    $model=\App::make('Werkzeugh\Cartengine\Interfaces\OrderInterface');
    $res=$model->where('transaction_id','=',$transaction_id)->first();

    return $res;

  }

  public function createNewOrderNr()
  {


     $maxID=App::make('Werkzeugh\Cartengine\Interfaces\OrderInterface')->query()->max('order_nr');


     if(is_object($maxID) )
         throw new \Werkzeugh\Cartengine\OrderNrCreationFailedException("$maxID");

     if($maxID<1)
        $maxID=Date('Y')*10000;

     return $maxID+1;

  }

  public function createNewInvoiceNr()
  {


     $maxID=App::make('Werkzeugh\Cartengine\Interfaces\OrderInterface')->query()->max('invoice_nr');


     if(is_object($maxID) )
         throw new \Werkzeugh\Cartengine\InvoiceNrCreationFailedException("$maxID");

     if($maxID<1)
        $maxID=100000;

     return $maxID+1;

  }

  public function setValuesForOrder($values,$transaction_id)
  {

    $rec=$this->getOrderAsModelByTransactionId($transaction_id);

    if($rec)
    {
      $rec->fill($values);
      $rec->save();
      return TRUE;
    }
    return FALSE;
  }

  public function forceSetValuesForOrder($values,$transaction_id)
  {

    $rec=$this->getOrderAsModelByTransactionId($transaction_id);

    if($rec)
    {
      foreach ($values as $key => $value) {
          $rec->$key=$value;
      }
      $rec->save();
      return TRUE;
    }
    return FALSE;
  }

  public function logMessageForOrder($msg,$data,$transaction_id)
  {


    \Log::info("cartengine-transaction[$transaction_id]: $msg", $data);

    $rec=$this->getOrderAsModelByTransactionId($transaction_id);
    if($rec)
    {
      $rec->add2Log($msg,$data);
      return TRUE;
    }
    return FALSE;
  }

  function addOrder(array $cart)
  {

    $orderdata=$cart['orderdata'];

    $transaction_id=$orderdata['transaction_id'];

    if($transaction_id)
    {
       $ordrec=$this->getOrderAsModelByTransactionId($transaction_id);

       if($ordrec && $ordrec->order_nr)
          return $this->getOrderAsArray($ordrec);  // do not save again, just return order, if it was already savedhr
       $ordrec=App::make('Werkzeugh\Cartengine\Interfaces\OrderInterface');
       $ordrec->transaction_id=$transaction_id;

    }
    else
    {
      throw new \Exception("no transaction_id given in orderdata");
    }

    if ($orderdata['order_nr']) {
        $ordrec->order_nr=$orderdata['order_nr'];
    } else {
        $ordrec->order_nr=$this->createNewOrderNr();
    }

    $ordrec->items_json=json_encode($cart['items']);

    if($orderdata['mail_html']) {
      $ordrec->mail_html=$this->cleanUpMailHtml($orderdata['mail_html']);
    }
    
    if($orderdata['status']) {
      $ordrec->status=$orderdata['status'];
    }
    
    unset(
    $orderdata['agb_confirmed'], //deprecated
    $orderdata['terms_accepted'],
    $orderdata['transaction_id'],
    $orderdata['order_nr'],
    $orderdata['mail_html'],
    $orderdata['order_nr'],
    $orderdata['status'],
    $orderdata['bookingrequest_wait_until_day'],
    $orderdata['bookingrequest_wait_until_time']
    );
    
    $ordrec->fill($orderdata);


    if (!$ordrec->status) {
      $ordrec->status='cart';
    }


    \Log::info("create order:{$ordrec->order_nr} created", array('order' =>  $this->getOrderAsArray($ordrec),'serverdata'=>$_SERVER));

    if($ordrec->save())
      return $this->getOrderAsArray($ordrec);
    else
      return NULL;

  }


  function finalizeOrder($transaction_id)
  {

    \Log::info('finalizeOrder  for order "'.$transaction_id.'"');

    if(is_array($transaction_id))
    {
// legacy code for old version of this function, an array was given instead of a
      $transaction_id=$transaction_id['orderdata']['transaction_id'];
    }


    $ret=Array('status'=>'error');


    if($transaction_id)
    {
      $ordrec=$this->getOrderAsModelByTransactionId($transaction_id);
      if($ordrec)
      {
        if($this->orderIsFinished($this->getOrderAsArray($ordrec)))
        {
          $ordrec->status='created';
          $ordrec->save();

          {
            $ret['status']='ok';
            $ret['order']=$this->getOrderAsArray($ordrec);
            \Log::info("Order id:{$ret[order][id]} created", array('order' => $ret['order']));
            $event = \Event::fire('order.created',array($ret['order']));
          }
        }

      }
    }



    return $ret;

  }


  function orderIsFinished(array $ordrec)
  {
        if(!$ordrec['order_nr'])
          return false ;

        if ($this->paymentTypeNeedsImmediatePayment($ordrec['payment_type']))
        {
            if($ordrec['payment_status']=='paid'){
                return true;
            }
            return false;
        }

        return true;

  }

  function paymentTypeNeedsImmediatePayment($type)
  {

      if($type=='sofort_com')
          return true;
      if($type=='paypal')
          return true;
      if($type=='wirecard')
          return true;

  }


  function cleanUpMailHtml($html)
  {

    $html=preg_replace('#<!--(.*)-->#Uis', '', $html);
    $html=preg_replace('# ?class="ng-[a-z]+"#mis','',$html);
    $html=preg_replace('# ?(ng-[a-z-]+|translate)="[^"]*"#mis','',$html);

    return $html;
  }


}



