<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Botble\Ecommerce\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderHistory;
use Botble\Ecommerce\Enums\ShippingMethodEnum;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Enums\OrderHistoryActionEnum;
use Botble\Ecommerce\Services\CreatePaymentForOrderService;
use Botble\Ecommerce\Models\OrderAddress;
use Botble\Ecommerce\Models\Address;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Ecommerce\Models\Invoice;
use Botble\Ecommerce\Models\InvoiceItem;
use Botble\Ecommerce\Facades\Discount;
use Botble\Ecommerce\Models\DiscountProduct;
use Botble\Ecommerce\Models\Discount as DiscountModel;
use Botble\Ecommerce\Models\MobileVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Botble\Ecommerce\Models\Currency;
use Illuminate\Support\Arr;
use Throwable;
use Botble\Ecommerce\Models\ShippingRule;
use Botble\Ecommerce\Models\Tax;
use App\Models\Promotion;

class OrderController extends Controller
{
    // private function prepareShipsyPayload(Order $order, array $products, $customer, OrderAddress $orderAddress, string $paymentMethod): array
    // {
    //     $totalQuantity = 0;
    //     $totalWeight = 0.0;
    //     $piecesDetail = [];

    //     foreach ($products as $product) {
    //         // Use the quantity from the order, not the product's total stock.
    //         $quantityInOrder = $product->qty;
    //         $totalQuantity += $quantityInOrder;

    //         // Ensure weight is numeric and calculate total. Default to a small weight if not set.
    //         $productWeight = is_numeric($product->weight) && $product->weight > 0 ? $product->weight : 0.5; // Default to 0.5 kg
    //         $totalWeight += $productWeight * $quantityInOrder;

    //         // The sale price of a single unit after discounts.
    //         $unitPrice = Arr::get($product->options, 'original_price', $product->price);
    //         if (Arr::get($product->options, 'sale_price')) {
    //             $unitPrice = Arr::get($product->options, 'sale_price');
    //         }

    //         $piecesDetail[] = [
    //             "description"        => $product->name,
    //             "declared_value"     => (string) number_format($unitPrice, 2, '.', ''),
    //             "weight"             => (string) $productWeight,
    //             // NOTE: Add product dimensions to your database for accurate data. Using defaults for now.
    //             "height"             => (string) (is_numeric($product->height) && $product->height > 0 ? $product->height : 10), // Default to 10cm
    //             "length"             => (string) (is_numeric($product->length) && $product->length > 0 ? $product->length : 10), // Default to 10cm
    //             "width"              => (string) (is_numeric($product->wide) && $product->wide > 0 ? $product->wide : 10), // Default to 10cm
    //             "piece_product_code" => $product->sku ?? (string)$product->id,
    //             "product_code"       => $product->sku ?? (string)$product->id,
    //         ];
    //     }
    //     $originDetails = [
    //         "name"           => 'Ahmed Al Maghribi Perfumes',
    //         "phone"          => '+965 6690 3786',
    //         "address_line_1" => 'Unit No. 07, Sama Mall Plot 83, Al Aqila, Block 5, Kuwait',
    //         "pincode"        => '00000',
    //         "city"           => 'Kuwait',
    //         "state"          => 'Kuwait',
    //         "country"        => 'Kuwait'
    //     ];

    //     $payload = [
    //         "consignment_type" => "forward",
    //         "movement_type" => "forward",
    //         "load_type" => "NON-DOCUMENT",
    //         "description" => implode(" / ", array_map(fn($p) => $p->name . " x" . $p->qty, $products)),
    //         "customer_code" => "",
    //         "reference_number" => "",
    //         "service_type_id" => "PREMIUM",
    //         "cod_amount"        => ($paymentMethod === 'cod') ? (string)number_format($order->amount, 2, '.', '') : "0.00",
    //         "invoice_amount"    => (string)number_format($order->sub_total, 2, '.', ''),
    //         "invoice_number"    => str_replace('#', '', $order->code),
    //         "invoice_date"      => $order->created_at->format('Y-m-d'),
    //         "declared_value"    => (float)number_format($order->sub_total, 2, '.', ''),
    //         "num_pieces"        => $totalQuantity,
    //         "customer_reference_number" => $order->code,
    //         "cod_favor_of"      => 'Ahmed Al Maghribi',
    //         "cod_collection_mode" => "cash",
    //         "dimension_unit" => "cm",
    //         "length" => "30",
    //         "width" => "20",
    //         "height" => "15",
    //         "weight_unit" => "kg",
    //         "weight"            => (string) ($totalWeight > 0 ? $totalWeight : 0.1),
    //         "origin_details"    => $originDetails,
    //         "destination_details" => [
    //             "name"           => $orderAddress->name,
    //             "phone"          => $orderAddress->phone,
    //             "alternate_phone"=> "",
    //             "address_line_1" => $orderAddress->address,
    //             "pincode"        => "00000",
    //             "city"           => $orderAddress->city,
    //             "state"          => $orderAddress->state,
    //             "country"        => $orderAddress->country,
    //         ],
    //         "return_details"    => $originDetails,
    //         "pieces_detail" => $piecesDetail
    //     ];

    //     return $payload;
    // }
    public function storeOrder(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {

        $validator = Validator::make($request->all(), [
            'products'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        if($request->input('finalPrice') <= 0) {
            return response()->json([
                'qtyMessage' => 'Total Amount should be greater than 0'
            ]);
        }
        $barcodes = [];

        foreach ($request->input('products') as $product) {
            $exisProduct = Product::where('id', $product['product_id'])->first();
             if (!$exisProduct) {
                return response()->json([
                    'notFound' => 'Product not found '.$product['product_name']
                ], 500);
            }

            

            if($product['quantity'] <= 0) {
                return response()->json([
                    'qtyMessage'          => 'Quantity should be greater than 0'
                ]);
            }

            if($exisProduct->quantity < $product['quantity']) {
                return response()->json([
                    'qtyMessage'          => $product['product_name'].' is Out Of Stock.'
                ]);
            }

            $maxQty = $exisProduct->maximum_order_quantity;
            // Only check when maxQty is NOT 0
            if ($maxQty != 0 && $product['quantity'] > $maxQty) {
                return response()->json([
                    'qtyMessage' => $product['product_name'].' exceeds the maximum allowed quantity of '.$maxQty.'.'
                ]);
            }

             // if(!is_null($product['discount'])) {
                // $discountFromDb = DiscountProduct::select('value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->first();
                // $requestHasDiscount = !is_null($product['discount']);
                // $dbHasDiscount = !is_null($discountFromDb);

                // if ($requestHasDiscount && !$dbHasDiscount) {
                //     // Request says there should be a discount, but none found in DB
                //     return response()->json([
                //         'discountMessage' => 'One or more Products were removed. Please add them again to continue.'
                //     ]);
                // }

                // if (!$requestHasDiscount && $dbHasDiscount) {
                //     // Request says there should be no discount, but one exists in DB
                //     return response()->json([
                //         'discountMessage' => 'One or more Products were removed. Please add them again to continue.'
                //     ]);
                // }

                // // Optional: if you want to compare actual values of discount too
                // if ($requestHasDiscount && $dbHasDiscount) {
                //     $match =
                //         $product['discount']['value'] == $discountFromDb->value &&
                //         $product['discount']['start_date'] == $discountFromDb->start_date &&
                //         $product['discount']['end_date'] == $discountFromDb->end_date;

                //     if (!$match) {
                //         return response()->json([
                //             'discountMessage' => 'One or more Products were removed. Please add them again to continue.'
                //         ]);
                //     }
                // }

                // // All matched, assign discount
                // $exisProduct->discount = $discountFromDb;
            // }
        }
         $focFromDb = Promotion::where('type', 'foc')
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->whereHas('focRules', function ($query) {
                    // $query->where('apply_to', '!=', 'individual');
                })
                ->whereHas('focRules.products', function ($query) use ($product) {
                    $query->where('product_id', $product['product_id']);
                })
                ->with(['focRules' => function ($query) {
                    // $query->where('apply_to', '!=', 'individual')
                        $query->select('id', 'promotion_id', 'min_threshold', 'max_threshold');
                }])
                ->first();
                
            $requestHasFOC = isset($product['type']) && $product['type'] == 'foc';
            $dbHasFOC = !is_null($focFromDb);

            // echo $requestHasFOC.'---'.$dbHasFOC.'---'.$product['product_id'];
            // echo "\n";

            if ($requestHasFOC && !$dbHasFOC) {
                // Request says there should be a discount, but none found in DB
                return response()->json([
                    'focMessage' => 'One or more Products were removed. Please add them again to continue. DB'
                ]);
            }

            if (!$requestHasFOC && $dbHasFOC) {
                // Request says there should be no discount, but one exists in DB
                return response()->json([
                    'focMessage' => 'One or more Products were removed. Please add them again to continue. Request '.$product['product_name']
                ]);
            }

            // Step 1: Determine if request says product is a BOGO free item
            $requestHasBOGO = isset($product['type']) && $product['type'] == 'bogo' && isset($product['is_gift']);

            // Step 2: Only run DB BOGO check if the request is for a BOGO free product
            $bogoFromDb = null;

            if ($requestHasBOGO) {
                // echo "bogo ".$product['product_name'];
                // echo "\n";
                $bogoFromDb = Promotion::where('type', 'buy_x_get_y')
                    ->whereDate('start_date', '<=', now())
                    ->whereDate('end_date', '>=', now())
                    ->whereHas('buyXGetYRules.products', function ($query) use ($product) {
                        $query->where('product_id', $product['product_id']);
                            // ->where('type', 'free'); // Ensure it only matches "get" products
                    })
                    ->first();
            }

            // Step 3: Validate mismatch between request and DB
            $dbHasBOGO = !is_null($bogoFromDb);

            if ($requestHasBOGO && !$dbHasBOGO) {
                return response()->json([
                    'bogoMessage' => 'One or more Products were removed. Please add them again to continue. DB'
                ]);
            }

            // if (!$requestHasBOGO && $dbHasBOGO) {
            //     return response()->json([
            //         'bogoMessage' => 'One or more Products were removed. Please add them again to continue. Request ' . $product['product_name']
            //     ]);
            // }

            array_push($barcodes, $exisProduct->barcode);
             $coupon_code = $request->input('couponCode');
        if(isset($coupon_code) && !empty($coupon_code)) {
            $coupon = Promotion::select('promotions.id', 'type', 'start_date', 'end_date', 'coupon_code AS code', 'percentage As value', 'apply_to')
                ->where('type', 'coupon')
                ->where('coupon_code', $coupon_code)
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->join('coupon_rules', 'promotions.id', '=', 'coupon_rules.promotion_id', 'left')
                ->first();

            if(!$coupon) {
                return response()->json(['couponMessage' => 'Invalid Coupon Code']);
            }
            // $order_address = OrderAddress::where('phone', $request->input('billingAddress.mobile'))->first();
            // // echo $order_address;
            // if($order_address) {
            //     $order = Order::where('id', $order_address->order_id)->first();
            //     $customer_discount = DB::table('ec_customer_used_coupons')->where('customer_id', $order->user_id)->where('discount_id', $coupon->id)->first();
            //     if($customer_discount) {
            //         return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
            //     }
            // }

            $customer = OrderAddress::join('payments', 'payments.order_id', '=', 'ec_order_addresses.order_id')->where('status', 'completed')->where('phone', $request->input('billingAddress.mobile'))->get();

            if(!$customer->isEmpty()) {
                if(strtolower($request->input('couponCode')) == 'welcome10') {
                    return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
                }
                $customer_discount = DB::table('ec_customer_used_coupons')->where('customer_id', $customer[0]->customer_id)->where('discount_id', $coupon->id)->first();
                if($customer_discount) {
                    return response()->json(['couponMessage' => 'You Have Already Used this Coupon Code']);
                }
            }
        }
          $cashback = Promotion::select('promotions.name', 'cashback_rules.id', 'cashback_percentage', 'cashback_amount', 'duration')->where('type', 'cashback')->where('start_date', '<=', now())->where('end_date', '>=', now())->leftJoin('cashback_rules', 'promotions.id', '=', 'cashback_rules.promotion_id')->first();
        if($cashback) {
            $coupon_code = !is_null($cashback->cashback_percentage) ? 'CASHBACK'.intval($cashback->cashback_percentage) : 'CASHBACK'.intval($cashback->cashback_amount);
            $coupon_type = !is_null($cashback->cashback_percentage) ? 'percent' : 'amount';
            $cashback_product_ids = CashbackProduct::select('product_id')->where('cashback_rule_id', $cashback->id)->pluck('product_id')->toArray();
            // echo "<pre>";print_r($cashback_products);
        } else {
            $cashback_product_ids = [];
        }

        $customer_id = $request->input('customer_id');

        if (!$customer_id) {
            $validator = Validator::make($request->all(), [
                'billingAddress.first_name'      => 'required|string|max:255',
                'billingAddress.last_name'      => 'required|string|max:255',
                'billingAddress.email'     => 'required|string|max:255',
                'billingAddress.mobile'     => 'required|numeric',
                'billingAddress.area'     => 'required|string',
                'billingAddress.building'     => 'required|string',
                'billingAddress.governorate'     => 'required|string',
                ]);
    
            if ($validator->fails()) {
                return response()->json($validator->errors());
            }
            
            $exisCustomer = Customer::where('email', $request->billingAddress['email'])->orWhere('phone', $request->billingAddress['mobile'])->first();
    
            if (!$exisCustomer) {
                $customer = Customer::create([
                    'name'      => $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                    'email'     => $request->input('billingAddress.email'),
                    'phone'     => $request->input('billingAddress.mobile'),
                    'password'  => $request->input('password') ? Hash::make($request->input('password')) : Hash::make('123456')
                ]);

                Address::create([
                    'name'      => $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                    'email'     => $request->input('billingAddress.email'),
                    'phone'     => $request->input('billingAddress.mobile'),
                    'state' => $request->input('billingAddress.governorate'),
                    'city' => $request->input('billingAddress.governorate'),
                    'country' => $request->input('billingAddress.country'),
                    'address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                    'customer_id' => $customer->id,
                ]);

                // $otp = rand(1111, 9999);

                // $ch = curl_init();

                // $passw = "11F2";
                // $pass = "$";
                // $p = "E89_6C3";
                // $password = $passw.$pass.$p;

                // curl_setopt($ch, CURLOPT_URL, "https://myinboxmedia.in/api/mim/SendSMS?userid=MIM2300278&pwd=".$password."&mobile=965".$request->input('billingAddress.mobile')."&sender=Ahmedper&msg=".$otp."".urlencode(' is your OTP for Registration')."&msgtype=16");
                // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

                // $result = curl_exec($ch);
                // if (curl_errno($ch)) {
                //     echo 'Error:' . curl_error($ch);die;
                // }
                // curl_close ($ch);

                // $customer->otp = $otp;
                // $customer->save();

                $customer_id = $customer->id;
            } else {
                $exisAddress = Address::where('customer_id', $exisCustomer->id)->first();
                if(!$exisAddress) {
                    Address::create([
                        'name'      => $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                        'email'     => $request->input('billingAddress.email'),
                        'phone'     => $request->input('billingAddress.mobile'),
                        'state' => $request->input('billingAddress.governorate'),
                        'city' => $request->input('billingAddress.governorate'),
                        'country' => $request->input('billingAddress.country'),
                        'address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                        'customer_id' => $exisCustomer->id,
                    ]);
                }
                $customer_id = $exisCustomer->id;
            }
        }

        // echo "<pre>";print_r(([
        //     'user_id' => $customer_id,
        //     'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
        //     'shipping_option' => $request->input('shipping_option'),
        //     'shipping_amount' => $request->input('shippingPrice') ? : 0,
        //     'tax_amount' => (($request->input('finalPrice') - 3) / 100) * 5 ? : 0,
        //     'sub_total' => $request->input('totalPrice') ? : 0,
        //     'amount' => $request->input('finalPrice') ? : 0,
        //     'coupon_code' => $request->input('coupon_code'),
        //     'discount_amount' => $request->input('discount_amount') ? : 0,
        //     'promotion_amount' => $request->input('promotion_amount') ? : 0,
        //     'discount_description' => $request->input('discount_description'),
        //     'description' => $request->input('note'),
        //     'is_confirmed' => 1,
        //     'is_finished' => 1,
        //     'status' => OrderStatusEnum::PROCESSING,
        //     'lang' => $request->input('locale'),
        // ]));die();
        // echo "<pre>";print_r([
        //     'user_id' => $customer_id,
        //     'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
        //     'shipping_option' => $request->input('shipping_option'),
        //     'shipping_amount' => $request->input('shippingPrice') ? : 0,
        //     'tax_amount' => (($request->input('finalPrice') - 3) / 100) * 5 ? : 0,
        //     'sub_total' => $request->input('totalPrice') ? : 0,
        //     'amount' => $request->input('finalPrice') ? : 0,
        //     'coupon_code' => $request->input('coupon_code'),
        //     'discount_amount' => $request->input('discount_amount') ? : 0,
        //     'promotion_amount' => $request->input('promotion_amount') ? : 0,
        //     'discount_description' => $request->input('discount_description'),
        //     'description' => $request->input('note'),
        //     'is_confirmed' => 1,
        //     'is_finished' => 1,
        //     'status' => OrderStatusEnum::PROCESSING,
        //     'order_lang' => $request->input('locale'),
        // ]);die();

        $userId = $customer_id;
        $now = Carbon::now();
        $fiveMinutesAgo = Carbon::now()->subMinutes(5);

        // Optionally, get order contents for matching (e.g. same total or cart hash)
        $total = $request->input('finalPrice'); // Example field

        $existingOrder = Order::where('user_id', $userId)
            ->where('amount', $total)
            ->where('created_at', '>=', $fiveMinutesAgo)
            ->whereHas('payment', function ($query) {
                $query->where('status', 'completed');
            })
            ->first();

        if ($existingOrder) {
            return response()->json([
                'duplicateOrderMessage' => 'You order has been placed already. Order Id: ' . $existingOrder->code
            ]);
        }

        $tax = Tax::select('percentage')->where('status', 'published')->first();

        $order = Order::create([
            'user_id' => $customer_id,
            'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
            'shipping_option' => $request->input('shipping_option'),
            'shipping_amount' => $request->input('shippingPrice') / (1 + ($tax->percentage / 100)),
            'shipping_amount_vat' => $request->input('shippingPrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100),
            'service_amount' => $request->input('servicePrice') / (1 + ($tax->percentage / 100)),
            'service_amount_vat' => $request->input('servicePrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100),
            'vat' => $tax->percentage,
            'tax_amount' => ($request->input('totalPrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)) + ($request->input('shippingPrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)) + ($request->input('servicePrice') / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)),
            'sub_total' => $request->input('totalPrice') ? : 0,
            'amount' => $request->input('finalPrice') ? : 0,
            'coupon_code' => $request->input('couponCode'),
            'discount_amount' => $request->input('discount_amount') ? : 0,
            'promotion_amount' => $request->input('promotion_amount') ? : 0,
            'discount_description' => $request->input('discount_description'),
            'description' => $request->input('note'),
            'is_confirmed' => 1,
            'is_finished' => 1,
            'status' => OrderStatusEnum::PROCESSING,
            'lang' => $request->input('locale'),
        ]);

        // echo "<pre>";print_r($order);die();

        if($order) {
            $finalOrderAddress = null;

            $loopSubTotal = 0;
            $loopTaxTotal = 0;
            $loopGrandTotal = 0;

            if($request->input('customer_id')) {
                $loggedInCustomer = Customer::where('id', $request->input('customer_id'))->first();
                $loggedInCustomerAdd = Address::where('customer_id', $loggedInCustomer->id)->first();
                if(!$loggedInCustomerAdd) {
                    Address::create([
                        'name'      => $loggedInCustomer->name,
                        'email'     => $loggedInCustomer->email,
                        'phone'     => $loggedInCustomer->phone,
                        'state' => $request->input('billingAddress.governorate'),
                        'city' => $request->input('billingAddress.governorate'),
                        'country' => $request->input('billingAddress.country'),
                        'address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                        'customer_id' => $loggedInCustomer->id,
                    ]);
                    $loggedInCustomerAdd = Address::where('customer_id', $loggedInCustomer->id)->first();
                }
                $finalOrderAddress = OrderAddress::query()->create([
                    'name' => $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $loggedInCustomer->name,
                    'phone' => $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $loggedInCustomer->phone,
                    'email' => $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $loggedInCustomer->email,
                    'state' => $request->input('shippingAddress.governorate') ? $request->input('shippingAddress.governorate') : $loggedInCustomerAdd->state,
                    'city' => $request->input('shippingAddress.governorate') ? $request->input('shippingAddress.governorate') : $loggedInCustomerAdd->city,
                    'country' => $request->input('shippingAddress.country') ? $request->input('shippingAddress.country') : $loggedInCustomerAdd->country,
                    'address' => $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $loggedInCustomerAdd->address,
                    'order_id' => $order->id,
                    'type' => $request->input('shippingAddress.first_name') ? 'shipping_address' : 'billing_address',
                ]);

                if($request->input('payment_method') == 'tap') {            
                    $data = [
                        "first_name"=> $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name') : explode(' ', $loggedInCustomer->name)[0],
                        "last_name"=> $request->input('shippingAddress.last_name') ? $request->input('shippingAddress.last_name') : explode(' ', $loggedInCustomer->name)[1],
                        "email"=> $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $loggedInCustomer->email,
                        "phone"=> $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $loggedInCustomer->phone,
                        // "street1"=> $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $loggedInCustomerAdd->address,
                        // "city"=> $request->input('shippingAddress.governorate') ? $request->input('shippingAddress.governorate') : $loggedInCustomerAdd->city,
                        // "state"=> $request->input('shippingAddress.governorate') ? $request->input('shippingAddress.governorate') : $loggedInCustomerAdd->state,
                        // "country"=> "KW",
                        // "zip"=> "54321"
                    ];
                    // $resp = $this->tapPayment($request, $data);
                    // return response()->json([
                    //     'redirect_url'     => $resp['redirect_url']
                    // ]);
                }

            } else {
                $finalOrderAddress = OrderAddress::query()->create([
                    'name' => $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name').' '.$request->input('shippingAddress.last_name') : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
                    'phone' => $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $request->input('billingAddress.mobile'),
                    'email' => $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $request->input('billingAddress.email'),
                    'state' => $request->input('shippingAddress.governorate') ? $request->input('shippingAddress.governorate') : $request->input('billingAddress.governorate'),
                    'city' => $request->input('shippingAddress.governorate') ? $request->input('shippingAddress.governorate') : $request->input('billingAddress.governorate'),
                    // 'zip_code' => $request->input('shippingAddress.zip_code'),
                    'country' => $request->input('shippingAddress.country') ? $request->input('shippingAddress.country') : $request->input('billingAddress.country'),
                    'address' => $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                    'order_id' => $order->id,
                    'type' => $request->input('shippingAddress.first_name') ? 'shipping_address' : 'billing_address',
                ]);

                if($request->input('payment_method') == 'tap') {
                    $data = [
                        "first_name"=> $request->input('shippingAddress.first_name') ? $request->input('shippingAddress.first_name') : $request->input('billingAddress.first_name'),
                        "last_name"=> $request->input('shippingAddress.last_name') ? $request->input('shippingAddress.last_name') : $request->input('billingAddress.last_name'),
                        "email"=> $request->input('shippingAddress.email') ? $request->input('shippingAddress.email') : $request->input('billingAddress.email'),
                        "phone"=> $request->input('shippingAddress.mobile') ? $request->input('shippingAddress.mobile') : $request->input('billingAddress.mobile'),
                        // "street1"=> $request->input('shippingAddress.area') ? $request->input('shippingAddress.area').' '.$request->input('shippingAddress.building') : $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
                        // "city"=> $request->input('shippingAddress.governorate') ? $request->input('shippingAddress.governorate') : $request->input('billingAddress.governorate'),
                        // "state"=> $request->input('shippingAddress.governorate') ? $request->input('shippingAddress.governorate') : $request->input('billingAddress.governorate'),
                        // "country"=> "KW",
                        // "zip"=> "54321"
                    ];
                    // $resp = $this->tapPayment($request, $data);
                    // return response()->json([
                    //     'redirect_url'     => $resp['redirect_url']
                    // ]);
                }
            }
            // die();
            OrderHistory::query()->create([
                'action' => OrderHistoryActionEnum::CREATE_ORDER_FROM_WEBSITE,
                'description' => trans('plugins/ecommerce::order.create_order_from_website'),
                'order_id' => $order->getKey(),
            ]);

            OrderHistory::query()->create([
                'action' => OrderHistoryActionEnum::CREATE_ORDER,
                'description' => trans(
                    'plugins/ecommerce::order.new_order',
                    ['order_id' => $order->code]
                ),
                'order_id' => $order->getKey(),
            ]);

            OrderHistory::query()->create([
                'action' => OrderHistoryActionEnum::CONFIRM_ORDER,
                'description' => trans('plugins/ecommerce::order.order_was_verified_by'),
                'order_id' => $order->getKey(),
                'user_id' => $customer_id,
            ]);

            $prod = array();
    
            foreach ($request->input('products') as $product) {
                
                $quantity = $product['quantity'] ? $product['quantity'] : 1;

                $exisProduct = Product::where('ec_products.id', $product['product_id'])
                // ->join('ec_tax_products', 'ec_products.id', '=', 'ec_tax_products.product_id')->join('ec_taxes', 'ec_taxes.id', '=', 'ec_tax_products.tax_id')
                ->first();

                // $exisProduct->discount = DiscountProduct::select('value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->first();

                // $exisProduct->coupon = DiscountProduct::select('code', 'value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->first();

                $exisProduct->qty = $quantity;

                 $exisProduct->discount = null;

                  $bogoOverrodeDiscount = isset($product['_original_discount']) && $product['_original_discount'] !== null;

                   if (!$bogoOverrodeDiscount) {    
                    $individualDiscount = Promotion::where('type', 'discount')
                        ->whereDate('start_date', '<=', now())
                        ->whereDate('end_date', '>=', now())
                        ->whereHas('discountRules', function ($query) {
                            $query->where('apply_to', 'individual');
                        })
                        ->whereHas('discountRules.individualRules', function ($query) use ($product) {
                            $query->where('product_id', $product['product_id']);
                        })
                        ->with(['discountRules' => function ($query) {
                            $query->where('apply_to', 'individual')
                                ->select('id', 'promotion_id', 'apply_to');
                        }, 'discountRules.individualRules' => function ($query) use ($product) {
                            $query->where('product_id', $product['product_id'])
                                ->select('discount_rule_id', 'product_id', 'value', 'discount_type', 'product_price', 'discount_amount', 'final_price');
                        }])
                        ->first();
                          if ($individualDiscount) {
                                $discountRule = $individualDiscount->discountRules->first();
                                 $individualRule = $discountRule ? $discountRule->individualRules->first() : null;
                                 if ($individualRule) {
                                    $exisProduct->discount = (object) [
                                        'name' => $individualDiscount->name,
                                        'value' => intval($individualRule->value),
                                        'apply_to' => $discountRule->apply_to,
                                        'discount_type' => $individualRule->discount_type,
                                        'product_price' => $individualRule->product_price,
                                        'discount_amount' => $individualRule->discount_amount,
                                        'final_price' => $individualRule->final_price,
                                        'start_date' => $individualDiscount->start_date->format('Y-m-d H:i:s'),
                                        'end_date' => $individualDiscount->end_date->format('Y-m-d H:i:s'),
                                    ];
                    }
                } else {
                    // If no individual discount, try to fetch discount for group/all products
                    $groupDiscount = Promotion::where('type', 'discount')
                        ->whereDate('start_date', '<=', now())
                        ->whereDate('end_date', '>=', now())
                        ->whereHas('discountRules', function ($query) {
                            $query->where('apply_to', '!=', 'individual');
                        })
                        ->whereHas('discountRules.products', function ($query) use ($product) {
                            $query->where('product_id', $product['product_id']);
                        })
                        ->with(['discountRules' => function ($query) {
                            $query->where('apply_to', '!=', 'individual')
                                ->select('id', 'promotion_id', 'percentage', 'apply_to');
                        }])
                        ->first();

                    if ($groupDiscount) {
                        $discountRule = $groupDiscount->discountRules->first();
                        if ($discountRule) {
                            $exisProduct->discount = (object) [
                                'name' => $groupDiscount->name,
                                'value' => intval($discountRule->percentage),
                                'apply_to' => $discountRule->apply_to,
                                'discount_type' => 'percent',
                                'product_price' => null,
                                'discount_amount' => null,
                                'final_price' => null,
                                'start_date' => $groupDiscount->start_date->format('Y-m-d H:i:s'),
                                'end_date' => $groupDiscount->end_date->format('Y-m-d H:i:s'),
                            ];
                        }
                    }
                 }
                }

                $exisProduct->qty = $quantity;
                 if((isset($product['is_gift']) && $product['is_gift'] == true)) {
                    $exisProduct->is_gift = 1;
                }

                if((isset($product['is_coupon']) && $product['is_coupon'] == true)) {
                    $exisProduct->is_coupon = 1;
                }



                // print_r($exisProduct);

                // if((isset($product['is_gift']) && $product['is_gift'] == true)) {
                //     $exisProduct->is_gift = 1;
                // }

                array_push($prod, $exisProduct);

                // $discount_price = '';
                // $sale_price = '';
               if (!is_null($exisProduct->discount) && $exisProduct->is_gift != 1 && $exisProduct->is_coupon != 1) {
                    if ($exisProduct->discount->discount_type == 'percent') {
                        $price = $exisProduct->price / (1 + ($tax->percentage / 100));
                        $total_amount = $price * $quantity;
                        $discount_percent = $exisProduct->discount->value;
                        $discount_amount = ($total_amount / 100) * $discount_percent;
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $tax->percentage;
                        $gross_amount = $net_amount + $tax_amount;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'order_id' => $order->id,
                            'product_id' => $product['product_id'],
                            'product_name' => $exisProduct->name,
                            'product_image' => $exisProduct->image,
                            'qty' => $quantity,
                            'weight' => $exisProduct->weight,
                            'price' => $price,
                            'total_amount' => $total_amount,
                            'discount_percent' => $discount_percent,
                            'discount_amount' => $discount_amount,
                            'net_amount' => $net_amount,
                            'tax_amount' => $tax_amount,
                            'gross_amount' => $gross_amount,
                            'product_options' => [],
                            'options' => json_encode($options),
                            'product_type' => $exisProduct->product_type,
                            'product_category' => $product['category_name'] ? $product['category_name'] : '',
                            'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                            'vat' => $tax->percentage,
                        ];
                        $loopSubTotal += $gross_amount;
                        $loopTaxTotal += $tax_amount;
                    } 
    // 2. Check if the discount is an AMOUNT
                                elseif ($exisProduct->discount->discount_type == 'amount') {
                                    $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                                    $total_amount = $price * $quantity;
                                    $sale_price = $exisProduct->discount->final_price / (1 + ($request->input('vatTax') / 100));
                                    $discount_percent = 0;
                                    $discount_amount = $total_amount - ($sale_price * $quantity);
                                    $net_amount = $total_amount - $discount_amount;
                                    $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                                    $gross_amount = $net_amount + $tax_amount;
                                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                                
                                    $orderProduct = [
                                        'order_id' => $order->id,
                                        'product_id' => $product['product_id'],
                                        'product_name' => $exisProduct->name,
                                        'product_image' => $exisProduct->image,
                                        'qty' => $quantity,
                                        'weight' => $exisProduct->weight,
                                        'price' => $price,
                                        'total_amount' => $total_amount,
                                        'discount_percent' => $discount_percent,
                                        'discount_amount' => $discount_amount,
                                        'net_amount' => $net_amount,
                                        'tax_amount' => $tax_amount,
                                        'gross_amount' => $gross_amount,
                                        'product_options' => [],
                                        'options' => json_encode($options),
                                        'product_type' => $exisProduct->product_type,
                                        'product_category' => $product['category_name'],
                                        'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                                        'vat' => $request->input('vatTax'),
                                        'campaign' => $exisProduct->discount->name,
                                    ];
                                    $loopSubTotal += $gross_amount;
                                    $loopTaxTotal += $tax_amount;
                                }             
}
elseif(!empty($product['coupon']) && !is_null($exisProduct->coupon) && !empty($exisProduct->coupon) && isset($exisProduct->coupon) && isset($exisProduct->coupon[strtolower($request->input('couponCode'))]) && $exisProduct->coupon[strtolower($request->input('couponCode'))]['code'] == strtolower($request->input('couponCode'))) {
                        $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        $total_amount = $price * $quantity;
                        $discount_percent = $exisProduct->coupon[strtolower($request->input('couponCode'))]['value'];
                        $discount_amount = ($total_amount / 100) * $discount_percent;
                        $net_amount = $total_amount - $discount_amount;
                        $tax_amount = ($net_amount / 100) * $request->input('vatTax');
                        $gross_amount = $net_amount + $tax_amount;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'order_id' => $order->id,
                            'product_id' => $product['product_id'],
                            'product_name' => $exisProduct->name,
                            'product_image' => $exisProduct->image,
                            'qty' => $quantity,
                            'weight' => $exisProduct->weight,
                            'price' => $price,
                            'total_amount' => $total_amount,
                            'discount_percent' => $discount_percent,
                            'discount_amount' => $discount_amount,
                            'net_amount' => $net_amount,
                            'tax_amount' => $tax_amount,
                            'gross_amount' => $gross_amount,
                            'product_options' => [],
                            'options' => json_encode($options),
                            'product_type' => $exisProduct->product_type,
                            'product_category' => $product['category_name'],
                            'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                            'vat' => $request->input('vatTax'),
                        ];
                        $loopSubTotal += $gross_amount;
                        $loopTaxTotal += $tax_amount;
                    } elseif(isset($product['is_gift']) && $product['is_gift'] == true) {
                        // echo 'FOC';
                        // echo '\n ';
                        $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        $total_amount = 0.00;
                        $discount_percent = 100;
                        $discount_amount = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
                        $net_amount = 0.00;
                        $tax_amount = 0.00;
                        $gross_amount = 0.00;
                        $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                    
                        $orderProduct = [
                            'order_id' => $order->id,
                            'product_id' => $product['product_id'],
                            'product_name' => $exisProduct->name,
                            'product_image' => $exisProduct->image,
                            'qty' => $quantity,
                            'weight' => $exisProduct->weight,
                            'price' => $price,
                            'total_amount' => $total_amount,
                            'discount_percent' => $discount_percent,
                            'discount_amount' => $discount_amount,
                            'net_amount' => $net_amount,
                            'tax_amount' => $tax_amount,
                            'gross_amount' => $gross_amount,
                            'product_options' => [],
                            'options' => json_encode($options),
                            'product_type' => $exisProduct->product_type,
                            'product_category' => '',
                            'product_subcategory' => '',
                            'vat' => $request->input('vatTax'),
                            'is_gift' => 1,
                            'campaign' => $product['campaign'],
                        ];
                    } elseif(!is_null($exisProduct->sale_price)) {
                    $price = $exisProduct->price / (1 + ($tax->percentage / 100));
                    $total_amount = $price * $quantity;
                    $discount_percent = $exisProduct->sale_price;
                    $discount_amount = ($total_amount / 100) * $discount_percent;
                    $net_amount = $total_amount - $discount_amount;
                    $tax_amount = ($net_amount / 100) * $tax->percentage;
                    $gross_amount = $net_amount + $tax_amount;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'order_id' => $order->id,
                        'product_id' => $product['product_id'],
                        'product_name' => $exisProduct->name,
                        'product_image' => $exisProduct->image,
                        'qty' => $quantity,
                        'weight' => $exisProduct->weight,
                        'price' => $price,
                        'total_amount' => $total_amount,
                        'discount_percent' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'net_amount' => $net_amount,
                        'tax_amount' => $tax_amount,
                        'gross_amount' => $gross_amount,
                        'product_options' => [],
                        'options' => json_encode($options),
                        'product_type' => $exisProduct->product_type,
                        'product_category' => $product['category_name'],
                        'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                        'vat' => $tax->percentage,
                    ];
                    $loopSubTotal += $gross_amount;
                    $loopTaxTotal += $tax_amount;
                }
                else {
                    $price = $exisProduct->price / (1 + ($tax->percentage / 100));
                    $total_amount = $price * $quantity;
                    $discount_percent = 0;
                    $discount_amount = 0.00;
                    $net_amount = $total_amount - $discount_amount;
                    $tax_amount = ($net_amount / 100) * $tax->percentage;
                    $gross_amount = $net_amount + $tax_amount;
                    $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
                    $orderProduct = [
                        'order_id' => $order->id,
                        'product_id' => $product['product_id'],
                        'product_name' => $exisProduct->name,
                        'product_image' => $exisProduct->image,
                        'qty' => $quantity,
                        'weight' => $exisProduct->weight,
                        'price' => $price,
                        'total_amount' => $total_amount,
                        'discount_percent' => $discount_percent,
                        'discount_amount' => $discount_amount,
                        'net_amount' => $net_amount,
                        'tax_amount' => $tax_amount,
                        'gross_amount' => $gross_amount,
                        'product_options' => [],
                        'options' => json_encode($options),
                        'product_type' => $exisProduct->product_type,
                        'product_category' => $product['category_name'],
                        'product_subcategory' => isset($product['subcategory_name']) ? $product['subcategory_name'] : '',
                        'vat' => $tax->percentage,
                    ];
                    $loopSubTotal += $gross_amount;
                    $loopTaxTotal += $tax_amount;
                }

                OrderProduct::query()->create($orderProduct);

                Product::query()
                    ->where('id', $product['product_id'])
                    ->where('with_storehouse_management', 1)
                    ->where('quantity', '>=', $quantity)
                    ->decrement('quantity', $quantity);

                // $url = "https://c21341-ifservice.cloudiax.com/api/ECommerce/StockStatus?itemCode=123456";
                // // $url = "https://c21341-ifservice.cloudiax.com/api/ECommerce/StockStatus?itemCode=".$exisProduct->barcode;

                // $ch = curl_init();

                // curl_setopt($ch, CURLOPT_URL, $url);
                // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // // Set the request method to POST
                // curl_setopt($ch, CURLOPT_POST, true);
                // curl_setopt($ch, CURLOPT_HTTPHEADER, [
                //     "Accept: application/json",
                //     "Company: KWT", 
                //     "Authorization: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJVc2VySUQiOiJhZG1pbiIsIkVtcElEIjoiMTAyNDgiLCJDb21wYW55IjoiIiwiV2hzQ29kZSI6IidDdXN0b20nLCdETV8wMScsJ0ZHXzAxJywnRk9DJywnSUNfVUFFJywnUE1fMDEnLCdTUF8wMDEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDQnLCdTUF8wMDUnLCdTUF8wMDYnLCdTUF8wMDcnLCdTUF8wMDgnLCdTUF8wMDknLCdTUF8wMTAnLCdTUF8wMTEnLCdTUF8wMTInLCdTUF8wMTMnLCdTUF8wMTQnLCcwMScsJ0NOMDAxXzAxJywnQ3VzdG9tJywnRE1fMDEnLCdGR18wMScsJ0ZHXzAyJywnRkdfMDMnLCdGT0MnLCdJQ18wMScsJ0lDX1VBRScsJ1BNXzAxJywnU1BfMDAxJywnU1BfMDAxXzEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDNfMScsJ1NQXzAwNCcsJ1NQXzAwNScsJ1NQXzAwNicsJ1NQXzAwNycsJ1NQXzAwOCcsJ1NQXzAwOScsJ1NQXzAxMCcsJ1NQXzAxMScsJ1NQXzAxMicsJ1NQXzAxMycsJ1NQXzAxNCcsJ1NQXzAxNScsJ1NQXzAxNicsJ1NQXzAxNycsJ1NQXzAxOScsJ1NQXzAyMCcsJ1NQXzAyMF8xJywnU1BfMDIxJywnU1BfMDIyJywnU1BfMDIzJywnU1BfMDI0JywnU1BfMDI1JywnU1BfMDI2JywnU1BfMDI3JywnU1BfMDI4JywnU1BfMDI4XzEnLCdTUF8wMjhfMicsJ1NQXzAyOScsJ1NQXzAzMCcsJ1NQXzAzMScsJ1ZOXzAwMScsJ0N1c3RvbScsJ0RNXzAxJywnRkdfMDEnLCdGT0MnLCdJQ19VQUUnLCdQTV8wMScsJ1NQXzAwMScsJ1NQXzAwMicsJ1NQXzAwMycsJ1NQXzAwNCcsJ1NQXzAwNScsJ1NQXzAwNicsJ1NQXzAwNycsJ1NQXzAwOCcsJzAxJywnQ3VzdG9tJywnRE1fMDEnLCdGR18wMScsJ0ZPQycsJ0lDXzAxJywnSUNfTW92JywnSUNfT0FQJywnSUNfVUFFJywnUE1fMDEnLCdTUF8wMDEnLCdTUF8wMDInLCdTUF8wMDMnLCdTUF8wMDQnLCdTUF8wMDUnLCdTUF8wMDYnLCdTUF8wMDcnLCdTUF8wMDgnLCdTUF8wMDknLCdTUF8wMTAnLCdTUF8wMTEnLCdTUF8wMTInLCdTUF8wMTMnLCdTUF8wMTQnLCdTUF8wMTUnLCdTUF8wMTYnLCdTUF8wMTcnLCdTUF8wMTgnLCdTUF8wMTknLCdTUF8wMjAnLCdTUF8wMjEnLCdTUF8wMjInLCdTUF8wMjMnLCdTUF8wMjQnLCdTUF8wMjUnLCdTUF8wMjYnLCdTUF8wMjcnLCdTUF8wMjgnLCdTUF8wMjknLCdTUF8wMzAnLCdTUF8wMzEnLCdTUF8wMzInLCdTUF8wMzMnLCdTUF8wMzQnLCdTUF8wMzUnLCdTUF8wMzYnLCdTUF8wMzcnLCdTUF8wMzgnLCdTUF8wMzknLCdTUF8wNDAnLCdTUF8wNDEnLCdTUF8wNDInLCdTUF8wNDMnLCdTUF8wNDQnLCdTUF8wNDUnLCdTUF8wNDYnLCdTUF8wNDcnLCdTUF8wNDgnLCdTUF8wNDknLCdTUF8wNTAnLCdTUF8wNTEnLCdTUF8wNTInLCdTUF8wNTMnLCdTUF8wNTQnLCdTUF8wNTUnLCdTUF8wNTYnLCdTUF8wNTcnLCdTUF8wNTgnLCdTUF8wNTknLCdTUF8wNjAnLCdTUF8wNjEnLCdUWVNfMDEnLCcwMScsJ0NOMDAxXzAxJywnQ04wMDJfMDEnLCdDTjAwM18wMScsJ0NOMDA0XzAxJywnQ04wMDVfMDEnLCdDTjAwNl8wMScsJ0N1c3RvbScsJ0RNXzAxJywnRkdfMDEnLCdGR18wMicsJ0ZPQycsJ0lDX09NTicsJ0lDX1RZUycsJ0lDX1VBRScsJ1BNXzAxJywnU01QXzAxJywnU1BfMDAxJywnU1BfMDAyJywnU1BfMDAzJywnU1BfMDA0JywnU1BfMDA1JywnU1BfMDA2JywnU1BfMDA3JywnU1BfMDA4JywnU1BfMDA5JywnU1BfMDEwJywnU1BfMDExJywnU1BfMDEyJywnU1BfMDEzJywnU1BfMDE1JywnU1BfMDE2JywnU1BfMDE3JywnU1BfMDE4JywnU1BfMDE5JywnU1BfMDIwJywnU1BfMDIxJywnU1BfMDIyJywnMDEnLCdBbWF6b24nLCdBVF8wMScsJ0JLXzAxJywnQlJBTkQnLCdDMDIwMjM1NicsJ0NOMDAxXzAxJywnQ04wMDJfMDEnLCdDTjAwM18wMScsJ0NOMDA0XzAxJywnQ04wMDVfMDEnLCdDTjAwNl8wMScsJ0NOMDA3XzAxJywnQ04wMDhfMDEnLCdDV19TTTAwMCcsJ0NXX1NNMDAxJywnQ1dfU00wMDInLCdDV19TTTAwMycsJ0NXX1NNMDA0JywnQ1dfU00wMDUnLCdDV19TTTAwNicsJ0NXX1NNMDA3JywnQ1dfU00wMDgnLCdDV19TTTAwOScsJ0NXX1NNMDEwJywnRE1fMDEnLCdETV8wMicsJ0RNXzAzJywnRE1fMDQnLCdETV8wNScsJ0RNXzA2JywnRUNfMDEnLCdGR18wMScsJ0ZPQycsJ0dGXzAxJywnSUNfQU1QJywnSUNfQkhSJywnSUNfS1NBJywnSUNfTW92JywnSUNfT01OJywnSUNfUUFUJywnSVQnLCdJVDAyJywnUEtfMDEnLCdQTV8wMScsJ1BNXzAyJywnUUNfMDEnLCdSJkQnLCdTS18wMScsJ1NMXzAxJywnU01QXzAxJywnU1BfMDAxJywnU1BfMDAyJywnU1BfMDAzJywnU1BfMDA0JywnU1BfMDA1JywnU1BfMDA2JywnU1BfMDA3JywnU1BfMDA4JywnU1BfMDA5JywnU1BfMDEwJywnU1BfMDExJywnU1BfMDEyJywnU1BfMDEzJywnU1BfMDE0JywnU1BfMDE1JywnU1BfMDE2JywnU1BfMDE3JywnU1BfMDE4JywnU1BfMDE5JywnU1BfMDIwJywnU1BfMDIxJywnU1BfMDIyJywnU1BfMDIzJywnU1BfMDI0JywnU1BfMDI1JywnU1BfMDI2JywnU1BfMDI3JywnU1BfMDI4JywnU1BfMDI5JywnU1BfMDMwJywnU1BfMDMxJywnU1BfMDMyJywnU1BfMDMyXzEnLCdTUF8wMzMnLCdTUF8wMzQnLCdTUF8wMzUnLCdTUF8wMzYnLCdTUF8wMzcnLCdTUF8wMzgnLCdTUF8wMzknLCdTUF8wNDAnLCdTUF8wNDEnLCdTUF8wNDInLCdTUF8wNDMnLCdTUF8wNDQnLCdTUF8wNDUnLCdTUF8wNDYnLCdTUF8wNDcnLCdTUF8wNDgnLCdTUF8wNDknLCdTUF8wNTAnLCdTUF8wNTEnLCdTUF8wNTInLCdTUF8wNTMnLCdTUF8wNTQnLCdTUF8wNTUnLCdTUF8wNTYnLCdTUF8wNTcnLCdTUF8wNTgnLCdTUF8wNTknLCdTUF8wNjAnLCdTUF8wNjEnLCdTUF8wNjInLCdTUF8wNjMnLCdTUF8wNjQnLCdTUF8wNjUnLCdTUF8wNjYnLCdTUF8wNjcnLCdTUF8wNjgnLCdTUF8wNjknLCdTUF8wNzAnLCdTUF8wNzEnLCdTUF8wNzInLCdTUF8wNzMnLCdTUF8wNzQnLCdTUF8wNzUnLCdTUF8wNzYnLCdTUF8wNzcnLCdTUF8wNzknLCdTUF8wODAnLCdTUF8wODEnLCdTUF8wODInLCdTUF8wODMnLCdTUF8wODQnLCdTUF8wODUnLCdTUF8wODYnLCdTUF8wODgnLCdTUF8wODknLCdTUF8wOTAnLCdTUF8wOTEnLCdTUF8wOTInLCdXSF8wMScsJ1dIXzAyJywnV0hfMDMnLCdXSF8wNCcsJ1dIXzA1JywnV0hfMDYnLCdXSF9EUk0nLCdXSF9WZW5kJyIsIlN0b3JlSUQiOiInJywnSE8nLCdPRkInLCdITycsJ0hPJywnUCZFJywnU01BJywnQktXJywnQkNDJywnQlNUJywnSERMJywnREFNJywnSklEJywnQlVLJywnUkFNJywnQ0NCJywnSE1UJywnTUhSJywnQU1CJywnQlNTJywnJywnSE8nLCdITycsJycsJ0pETycsJ01ETycsJ0hPJywnSE8nLCcnLCdITycsJ1AmRScsJ0tBUycsJ0tBU1MnLCdKUUInLCdEQVQnLCdEQVRTJywnTk9SJywnQVNNJywnVEJBJywnQVpNJywnQktSJywnU0tEJywnVEdNJywnT0JNJywnSlVNJywnUUJBJywnS09TJywnU1NKJywnTU9OJywnU0FGJywnUUJGJywnS01TJywnS01TUycsJ01BRycsJ1lSTScsJ01VRycsJ01SSicsJ1NRSicsJ01ESCcsJ01ERycsJ01DVCcsJ01DVFMnLCdWTUNUJywnUkhCJywnT0JIJywnQkFTJywnS1NWJywnJywnSE8nLCcnLCdITycsJ0hPJywnUCZFJywnS1NNJywnSlJLJywnS01BJywnS09EJywnR0FUJywnQkxWJywnTUdUJywnTUdDJywnJywnSE8nLCdITycsJ09GTycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdQJkUnLCdTTVQnLCdTS0snLCdTRUInLCdCUksnLCdTTEwnLCdTVVInLCdOSVonLCdTV1EnLCdTT00nLCdTQU0nLCdCUk0nLCdFQlInLCdTQlgnLCdCRFknLCdLQlInLCdBTVInLCdTTk0nLCdBVk0nLCdMV00nLCdKTE4nLCdBS00nLCdBS0InLCdNU04nLCdTTlcnLCdSU1QnLCdCUkEnLCdZQU4nLCdTTE4nLCdTTFUnLCdTQUQnLCdNT00nLCdRVVInLCdCSUQnLCdLQU0nLCdLVUQnLCdTTUwnLCdTTlMnLCdDQ00nLCdNT08nLCdDQ1MnLCdKTFMnLCdPQVMnLCdTU1MnLCdETksnLCdCSEwnLCdNQVQnLCdBTlMnLCdBU0snLCdLQlMnLCdTTVMnLCdGTEonLCdEUU0nLCdFQlMnLCdGQU4nLCdCRFMnLCdBTVMnLCdCREQnLCdPT1MnLCdUTUQnLCdTV1MnLCdNVVMnLCdITycsJycsJycsJycsJycsJycsJycsJycsJycsJycsJ09GUScsJ0hPJywnJywnSE8nLCdITycsJ0hPJywnUCZFJywnSE8nLCdBWlknLCdTSEYnLCdOU1InLCdESEYnLCdNUVInLCdBTUonLCdET00nLCdBTUsnLCdMQkInLCdBV1MnLCdNUksnLCdBRlMnLCdXQVEnLCdRT1MnLCdRUk4nLCdJR1cnLCdFWkQnLCdWSUwnLCdOQVMnLCdTSE4nLCdXQVQnLCcnLCdITycsJ0hPJywnSE8nLCcnLCcnLCcnLCcnLCcnLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0FFQycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ1AmRScsJ0hPJywnSE8nLCcnLCcnLCdITycsJ0hPJywnREZNJywnQlNNJywnQk5ZJywnQ1RNJywnRE1LJywnS0hMJywnQUpDJywnTVpNJywnQUZNJywnQUFNJywnQldNJywnQlNHJywnQlNYJywnQUdNJywnQUJNJywnQUJDJywnTUZDJywnRFJDJywnREFGJywnRkpNJywnQUtIJywnS0hLJywnTU5NJywnUkFLJywnU0hNJywnTVJEJywnU1JDJywnU0JTJywnU01NJywnTUFNJywnVUFRJywnSlJOJywnSlJNJywnU1FNJywnUk1aJywnQVNTJywnQkFSJywnS0hNJywnTU9RJywnRExNJywnQVlSJywnVUNKJywnQUdaJywnUkhNJywnVUNBJywnVUNCJywnRkNDJywnR0JWJywnRFJNJywnU0NIJywnSFRUJywnTVNGJywnSk1NJywnWkNDJywnR1lNJywnRkNNJywnTVNNJywnREhEJywnUklGJywnS0JNJywnSE1EJywnUldEJywnS1dTJywnQUFLJywnQlJTJywnRE9TJywnU0xNJywnREVSJywnU0NEJywnS0xGJywnU0JBJywnTURNJywnSlJGJywnTExaJywnRkpTJywnUkZNJywnRE1CJywnTVJCJywnREhNJywnSURXJywnSkNQJywnRFNTJywnTVNLJywnSE1BJywnRElCJywnRFNRJywnVU1CJywnQUtEJywnSFRTJywnWUFTJywnR0JJJywnSE8nLCdITycsJ0hPJywnSE8nLCdITycsJ0hPJywnRFdTJywnJyIsIlRlcm1pbmFsSUQiOiIiLCJzYWxlc1BlcnNvbklkIjoiIiwiem9uZUlkIjoiJyonIiwiZXhwIjoxNzczNTU5MjYyfQ.JZfGnaPSXmCanQfq3OWPRkYqqzy_rM9LLyLLiTLMFOo"
                // ]);

                // $response = curl_exec($ch);

                // if (curl_errno($ch)) {
                //     echo 'Error: ' . curl_error($ch);
                // }

                // curl_close($ch);
            }
            // die(';;;');

            if($cashback) {
                    $customer_cash_back_coupon = DB::table('coupon_customers')->where('customer_id', $customer_id)->where('cashback_rule_id', $cashback->id)->first();

                    if (in_array($product['product_id'], $cashback_product_ids) && !$customer_cash_back_coupon) {
                        $start_date = now();
                        $exist_coupon_rule = Promotion::select('coupon_rules.id')->where('coupon_code', $coupon_code)->where('type', 'coupon')->where('start_date', '<=', now())->where('end_date', '>=', now())->leftJoin('coupon_rules', 'promotions.id', '=', 'coupon_rules.promotion_id')->first();

                        if (!$exist_coupon_rule) {
                            $promotion = Promotion::create([
                                'name'      => $coupon_code,
                                'type'     => 'coupon',
                                'start_date'     => $start_date,
                                'end_date' => Carbon::parse($start_date)->addDays($cashback->duration),
                            ]);
                            if($promotion) {
                                $coupon_rule = CouponRule::create([
                                    'promotion_id'      => $promotion->id,
                                    'coupon_code'     => $coupon_code,
                                    'apply_to' => 'customer',
                                    'coupon_type' => $coupon_type,
                                    'percentage' => $cashback->cashback_percentage,
                                    'amount' => $cashback->cashback_amount,
                                ]);
                                if($coupon_rule) {
                                    DB::table('coupon_customers')->insert([
                                        'coupon_rule_id' => $coupon_rule->id,
                                        'cashback_rule_id' => $cashback->id,
                                        'customer_id' => $customer_id,
                                        'created_at' => now()
                                    ]);
                                }
                            }
                        } else {
                            DB::table('coupon_customers')->insert([
                                'coupon_rule_id' => $exist_coupon_rule->id,
                                'cashback_rule_id' => $cashback->id,
                                'customer_id' => $customer_id,
                                'created_at' => now()
                            ]);
                        }
                    }
            }

            $shipping_service_charges = ShippingRule::select('price')->get();
            $serviceAmount = round((float) $shipping_service_charges[1]->price, 2);

            $tax = Tax::select('percentage')->where('status', 'published')->first();
            $loopGrandTotal = $loopSubTotal;
            $shippingAmount = round((float) $shipping_service_charges[0]->price, 2);
            $loopGrandTotal += $shippingAmount;
            $loopGrandTotal += $serviceAmount;

            // echo $tax;
            // echo "<br>";
            // echo $shipping_service_charges;
            // echo "<br>";
            // echo $serviceAmount;
            // echo "<br>";
            // echo $shippingAmount;
            // echo "<br>";
            // echo $loopGrandTotal;
            // echo "<br>";
            // echo $loopSubTotal;
            // echo "<br>";die;

            $order->update([
                'sub_total' => $loopSubTotal,
                'tax_amount' => $loopTaxTotal + ($shippingAmount / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)) + ($serviceAmount / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)),
                'amount' => $loopGrandTotal,
                'shipping_amount' => $shippingAmount / (1 + ($tax->percentage / 100)),
                'shipping_amount_vat' => $shippingAmount / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100),
                'service_amount' => $serviceAmount / (1 + ($tax->percentage / 100)),
                'service_amount_vat' => $serviceAmount / (1 + ($tax->percentage / 100)) * ($tax->percentage / 100)
            ]);

            if ($couponCode = $request->input('couponCode')) {
                Discount::getFacadeRoot()->afterOrderPlaced($couponCode, $request->input('customer_id') ? $request->input('customer_id') : $customer_id);
            }

            if($request->input('customer_id')) {
                $loggedInCustomer = Customer::where('id', $request->input('customer_id'))->first();
            } else {
                $loggedInCustomer = null;
            }

            // $invoice = Invoice::query()->create([
            //     'reference_type' => 'Botble\Ecommerce\Models\Order',
            //     'reference_id' => $order->id,
            //     'customer_name' => $loggedInCustomer ? $loggedInCustomer->name : $request->input('billingAddress.first_name').' '.$request->input('billingAddress.last_name'),
            //     'customer_email' => $loggedInCustomer ? $loggedInCustomer->email : $request->input('billingAddress.email'),
            //     'customer_phone' => $loggedInCustomer ? $loggedInCustomer->phone : $request->input('billingAddress.mobile'),
            //     'customer_address' => $request->input('billingAddress.area').' '.$request->input('billingAddress.building'),
            //     'sub_total' => $request->input('totalPrice') ? : 0,
            //     'tax_amount' => ($request->input('totalPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)) + ($request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100)),
            //     'shipping_amount' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)),
            //     'shipping_amount_vat' => $request->input('shippingPrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
            //     'service_amount' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)),
            //     'service_amount_vat' => $request->input('servicePrice') / (1 + ($request->input('vatTax') / 100)) * ($request->input('vatTax') / 100),
            //     'vat' => $request->input('vatTax'),
            //     'discount_amount' => $request->input('discount_amount') ? : 0,
            //     'shipping_method' => $request->input('shipping_method') ? : ShippingMethodEnum::DEFAULT,
            //     'coupon_code' => $request->input('couponCode'),
            //     'discount_description' => $request->input('discount_description'),
            //     'amount' => $request->input('finalPrice'),
            //     'payment_id' => $order->payment_id,
            //     'status' => $request->input('payment_status'),
            // ]);

            // foreach ($request->input('products') as $product) {
                
            //     $quantity = $product['quantity'] ? $product['quantity'] : 1;

            //     $exisProduct = Product::where('id', $product['product_id'])->first();

            //     $exisProduct->discount = DiscountProduct::select('value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->first();

            //     $exisProduct->coupon = DiscountProduct::select('code', 'value', 'start_date', 'end_date')->where('product_id', $product['product_id'])->whereNotNull('code')->whereDate('start_date', '<=', now())->whereDate('end_date', '>=', now())->join('ec_discounts', 'ec_discounts.id', '=', 'ec_discount_products.discount_id', 'left')->first();

            //     if(!is_null($exisProduct->discount)) {
            //         $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
            //         $total_amount = $price * $quantity;
            //         $discount_percent = $exisProduct->discount->value;
            //         $discount_amount = ($total_amount / 100) * $discount_percent;
            //         $net_amount = $total_amount - $discount_amount;
            //         $tax_amount = ($net_amount / 100) * $request->input('vatTax');
            //         $gross_amount = $net_amount + $tax_amount;
            //         $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
            //         $orderProduct = [
            //             'invoice_id' => $invoice->id,
            //             'reference_type' => 'Botble\Ecommerce\Models\Product',
            //             'reference_id' => $exisProduct->id,
            //             'name' => $exisProduct->name,
            //             'description' => $exisProduct->description,
            //             'image' => $exisProduct->image,
            //             'qty' => $quantity,
            //             'price' => $price,
            //             'sub_total' => $total_amount,
            //             'discount_percent' => $discount_percent,
            //             'discount_amount' => $discount_amount,
            //             'net_amount' => $net_amount,
            //             'tax_amount' => $tax_amount,
            //             'gross_amount' => $gross_amount,
            //             'amount' => $gross_amount,
            //             'options' => json_encode($options),
            //         ];
            //     } elseif(!is_null($exisProduct->coupon)) {
            //         $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
            //         $total_amount = $price * $quantity;
            //         $discount_percent = $exisProduct->coupon->value;
            //         $discount_amount = ($total_amount / 100) * $discount_percent;
            //         $net_amount = $total_amount - $discount_amount;
            //         $tax_amount = ($net_amount / 100) * $request->input('vatTax');
            //         $gross_amount = $net_amount + $tax_amount;
            //         $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
            //         $orderProduct = [
            //             'invoice_id' => $invoice->id,
            //             'reference_type' => 'Botble\Ecommerce\Models\Product',
            //             'reference_id' => $exisProduct->id,
            //             'name' => $exisProduct->name,
            //             'description' => $exisProduct->description,
            //             'image' => $exisProduct->image,
            //             'qty' => $quantity,
            //             'price' => $price,
            //             'sub_total' => $total_amount,
            //             'discount_percent' => $discount_percent,
            //             'discount_amount' => $discount_amount,
            //             'net_amount' => $net_amount,
            //             'tax_amount' => $tax_amount,
            //             'gross_amount' => $gross_amount,
            //             'amount' => $gross_amount,
            //             'options' => json_encode($options),
            //         ];
            //     } elseif(!is_null($exisProduct->sale_price)) {
            //         $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
            //         $total_amount = $price * $quantity;
            //         $sale_price = $exisProduct->sale_price / (1 + ($request->input('vatTax') / 100));
            //         $discount_percent = 0;
            //         $discount_amount = $total_amount - ($sale_price * $quantity);
            //         $net_amount = $total_amount - $discount_amount;
            //         $tax_amount = ($net_amount / 100) * $request->input('vatTax');
            //         $gross_amount = $net_amount + $tax_amount;
            //         $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
            //         $orderProduct = [
            //              'invoice_id' => $invoice->id,
            //             'reference_type' => 'Botble\Ecommerce\Models\Product',
            //             'reference_id' => $exisProduct->id,
            //             'name' => $exisProduct->name,
            //             'description' => $exisProduct->description,
            //             'image' => $exisProduct->image,
            //             'qty' => $quantity,
            //             'price' => $price,
            //             'sub_total' => $total_amount,
            //             'discount_percent' => $discount_percent,
            //             'discount_amount' => $discount_amount,
            //             'net_amount' => $net_amount,
            //             'tax_amount' => $tax_amount,
            //             'gross_amount' => $gross_amount,
            //             'amount' => $gross_amount,
            //             'options' => json_encode($options),
            //         ];
            //     }
            //     // elseif(isset($product['is_gift']) && $product['is_gift'] == true) {
            //     //     $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
            //     //     $total_amount = 0.00;
            //     //     $discount_percent = 100;
            //     //     $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
            //     //     $net_amount = 0.00;
            //     //     $tax_amount = 0.00;
            //     //     $gross_amount = 0.00;
            //     //     $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
            //     //     $orderProduct = [
            //     //         'invoice_id' => $invoice->id,
            //     //         'reference_type' => 'Botble\Ecommerce\Models\Product',
            //     //         'reference_id' => $exisProduct->id,
            //     //         'name' => $exisProduct->name,
            //     //         'description' => $exisProduct->description,
            //     //         'image' => $exisProduct->image,
            //     //         'qty' => $quantity,
            //     //         'price' => $price,
            //     //         'sub_total' => $total_amount,
            //     //         'discount_percent' => $discount_percent,
            //     //         'discount_amount' => $discount_amount,
            //     //         'net_amount' => $net_amount,
            //     //         'tax_amount' => $tax_amount,
            //     //         'gross_amount' => $gross_amount,
            //     //         'amount' => $gross_amount,
            //     //         'options' => json_encode($options)
            //     //     ];
            //     // }
            //     else {
            //         $price = $exisProduct->price / (1 + ($request->input('vatTax') / 100));
            //         $total_amount = $price * $quantity;
            //         $discount_percent = 0;
            //         $discount_amount = 0.00;
            //         $net_amount = $total_amount - $discount_amount;
            //         $tax_amount = ($net_amount / 100) * $request->input('vatTax');
            //         $gross_amount = $net_amount + $tax_amount;
            //         $options = array('name' => $exisProduct->name, 'image' => $exisProduct->image, 'attributes' => ' ', 'taxRate' => $exisProduct->percentage, 'options' => [], 'extras' => [], 'sku' => $exisProduct->sku, 'weight' => $exisProduct->weight, 'original_price' => $exisProduct->price, 'product_type' => $exisProduct->product_type);
                
            //         $orderProduct = [
            //             'invoice_id' => $invoice->id,
            //             'reference_type' => 'Botble\Ecommerce\Models\Product',
            //             'reference_id' => $exisProduct->id,
            //             'name' => $exisProduct->name,
            //             'description' => $exisProduct->description,
            //             'image' => $exisProduct->image,
            //             'qty' => $quantity,
            //             'price' => $price,
            //             'sub_total' => $total_amount,
            //             'discount_percent' => $discount_percent,
            //             'discount_amount' => $discount_amount,
            //             'net_amount' => $net_amount,
            //             'tax_amount' => $tax_amount,
            //             'gross_amount' => $gross_amount,
            //             'amount' => $gross_amount,
            //             'options' => json_encode($options),
            //         ];
            //     }

            //     InvoiceItem::query()->create($orderProduct);
            // }

            if($request->input('payment_method') == 'tap') {
                $resp = $this->tapPayment($request, $data, $order);
                if(!isset($resp['errors']) && $resp['status'] == 'INITIATED') {
                    return response()->json([
                        'message'          => 'Redirecting to Tap...',
                        'order_id'         => $order->code,
                        'payment_method'   => $request->input('payment_method'),
                        'total'            => $order->amount,
                        'sub_total'        => $order->sub_total,
                        'shipping_amount'  => $order->shipping_amount,
                        // 'products'         => $prod,
                        'redirect_url'     => $resp['transaction']['url']
                    ]);
                } else {
                    return response()->json([
                        'message'          => $resp['errors'][0]['description']
                    ]);
                }
            }

            // $request['payment_status'] = 'completed';
            $createPaymentForOrderService->execute(
                $order,
                $request->input('payment_method'),
                'completed',
                $customer_id
            );

            $filteredProducts = array_map(function ($item) {

                $product = [
                    'id' => $item['id'],
                    'product_id' => $item['id'],
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'qty' => $item['qty'],
                    'discount' => $item['discount'],
                    'coupon' => $item['coupon'],
                    'sale_price' => $item['sale_price'],
                ];

            
                if (isset($item['is_gift'])) {
                    $product['is_gift'] = $item['is_gift'];
                }

                return $product;

            }, $prod);

            
            // // =================================================================
            // // START: SHipsy Soft Data Upload Integration
            // // =================================================================
            // try {
            //     // Step 1: Get the Shipsy API Key from your environment variables.
            //     // It's crucial to store secrets like API keys in your .env file, not in the code.
            //     $shipsyApiKey = env('SHIPSY_API_KEY');
                
            //     if ($shipsyApiKey) {
            //         // Step 2: Prepare the payload using our helper function.
            //         $shipsyPayload = $this->prepareShipsyPayload($order, $prod, $loggedInCustomer, $finalOrderAddress, $request->input('payment_method'));
                    
            //         Log::info('Preparing to send payload to Shipsy for order ' . $order->code, ['payload' => $shipsyPayload]);

            //         // Step 3: Make the API call to Shipsy.
            //         $shipsyApiUrl = "https://app.shipsy.in/api/customer/integration/consignment/upload/softdata/v2";
                    
            //         $response = Http::withHeaders([
            //             'api-key' => $shipsyApiKey,
            //             'Content-Type' => 'application/json'
            //         ])->post($shipsyApiUrl, $shipsyPayload);

            //         // Step 4: (Optional but Recommended) Log the outcome from Shipsy.
            //         if ($response->successful()) {
            //             Log::info('Successfully sent order ' . $order->code . ' to Shipsy.', ['response' => $response->json()]);
            //         } else {
            //             // Log a detailed error if the API call fails for any reason.
            //             Log::error('Failed to send order ' . $order->code . ' to Shipsy.', [
            //                 'status' => $response->status(),
            //                 'response' => $response->body()
            //             ]);
            //         }
            //     } else {
            //         Log::warning('Shipsy API key is not configured. Skipping API call for order ' . $order->code);
            //     }

            // } catch (Throwable $e) {
            //     // Catch any exception during the API call and log it.
            //     // This prevents the entire order process from failing if Shipsy is down.
            //     Log::error('An exception occurred while trying to send order ' . $order->code . ' to Shipsy.', [
            //         'message' => $e->getMessage(),
            //         'trace' => $e->getTraceAsString(),
            //     ]);
            // }
            // // =================================================================
            // // END: SHipsy Soft Data Upload Integration
            // // =================================================================


            return response()->json([
                'message'          => 'Order created successfully',
                'order_id'         => $order->code,
                'payment_method'   => $request->input('payment_method'),
                'total'            => $order->amount,
                'sub_total'        => $order->sub_total,
                'shipping_amount'  => $order->shipping_amount,
                'products'         => $filteredProducts
            ]);
        }
    }

    public function tapPayment(Request $request, $customerData, $order) {
        $requestParams = [
            "amount" => $order->amount,
            "currency" => "KWD",
            "reference" => [
                "order" => explode("#", $order->code)[1]
            ],
            "customer" => [
                "first_name" => $request->input('billingAddress.first_name'),
                "last_name" => $request->input('billingAddress.last_name'),
                "email" => $request->input('billingAddress.email'),
                "phone" => [
                    "country_code" => "965",
                    "number" => $request->input('billingAddress.mobile'),
                ]
            ],
            "merchant" => [
                "id" => env('TAP_MERCHANT_ID'),
                // "id" => "19407760"
            ],
            "source" => [
                "id" => "src_card"
            ],
            "redirect" => [
                "url" => "http://localhost/ahmed-admin-kw/public/api/tapPaymentRedirect?order_number=".base64_encode($order->code)
            ],
        ];

        // $SERVER_KEY = env('TAP_SERVER_KEY');
        // $SERVER_KEY = 'sk_test_gBXF25QfuDiU1VEMao6bP4HA';
        $SERVER_KEY = 'sk_live_DVoHY2FQcIgXjElnyuSCTPm3';
        // $BASE_URL = env('TAP_BASE_URL');
        $BASE_URL = 'https://api.tap.company/v2/charges';

        // $data['profile_id'] = $PROFILE_ID;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $BASE_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($requestParams, true),
            CURLOPT_HTTPHEADER => array(
                'authorization: Bearer ' . $SERVER_KEY,
                'Content-Type:application/json'
            ),
        ));

        $response = json_decode(curl_exec($curl), true);
        curl_close($curl);
        // print_r($response);die;
        return $response;
    }

    public function tapPaymentRedirect(Request $request, CreatePaymentForOrderService $createPaymentForOrderService) {
        // echo "<pre>";print_r($request->all());die;
        // $request->query('email');die;
        $payment_id = $request->input('tap_id') ? $request->input('tap_id') : $request->query('tap_id');
        // $customer = Customer::where('email', base64_decode($request->query('email')))->first();
        // $order = Order::where('user_id', $customer->id)->orderBy('id', 'desc')->first();
        $order = Order::where('code', base64_decode($request->query('order_number')))->orderBy('id', 'desc')->first();
        // echo "<pre>";print_r($order);
        $BASE_URL = env('TAP_BASE_URL').'/';
        // $BASE_URL = 'https://api.tap.company/v2/charges/';
        $SERVER_KEY = env('TAP_SERVER_KEY');
        // $SERVER_KEY = 'sk_test_gBXF25QfuDiU1VEMao6bP4HA';
        // $SERVER_KEY = 'sk_live_DVoHY2FQcIgXjElnyuSCTPm3';

        // Initialize cURL session
        $ch = curl_init();

        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $BASE_URL.$payment_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return response instead of outputting it
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification (useful for testing)
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'authorization: Bearer ' . $SERVER_KEY,
        ]);
        // Execute cURL session
        // $response = curl_exec($ch);

        // Check for errors
        // if (curl_errno($ch)) {
        //     echo 'cURL Error: ' . curl_error($ch);
        // }

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);
        // echo "<pre>";print_r($response);die;

        $createPaymentForOrderService->execute(
            $order,
            'tap',
            $response['status'],
            $order->user_id,
            $request->input('tap_id'),
            isset($response['response']['message']) ? $response['response']['message'] : $response['status'],
        );

        // $paymentStatus = $response['status'];
        // $paymentStat = $paymentStatus == 'CAPTURED' ? 'completed' : 'failed';
        // if($paymentStat == 'completed') {
        //     try {
        //         $shipsyApiKey = env('SHIPSY_API_KEY');
        //         if ($shipsyApiKey) {
        //             $shipsyPayload = $this->prepareShipsyPayload($order, $prod, $loggedInCustomer, $finalOrderAddress, $request->input('payment_method'));
        //             Log::info('Preparing to send payload to Shipsy for order ' . $order->code, ['payload' => $shipsyPayload]);
        //             $shipsyApiUrl = "https://app.shipsy.in/api/customer/integration/consignment/upload/softdata/v2";  
        //             $response = Http::withHeaders([
        //                 'api-key' => $shipsyApiKey,
        //                 'Content-Type' => 'application/json'
        //             ])->post($shipsyApiUrl, $shipsyPayload);
        //             if ($response->successful()) {
        //                 Log::info('Successfully sent order ' . $order->code . ' to Shipsy.', ['response' => $response->json()]);
        //             } else {
        //                 Log::error('Failed to send order ' . $order->code . ' to Shipsy.', [
        //                     'status' => $response->status(),
        //                     'response' => $response->body()
        //                 ]);
        //             }
        //         } else {
        //             Log::warning('Shipsy API key is not configured. Skipping API call for order ' . $order->code);
        //         }
        //     } catch (Throwable $e) {
        //         Log::error('An exception occurred while trying to send order ' . $order->code . ' to Shipsy.', [
        //             'message' => $e->getMessage(),
        //             'trace' => $e->getTraceAsString(),
        //         ]);
        //     }
        // }

        header('Location: http://localhost:3000/'.$order->lang.'/shop-order-payment-complete?q='.base64_encode($order->code));exit();
    }

    public function trackOrder(Request $request)
    {
        $currency = Currency::select('decimals')->where('is_default', 1)->first();
        $decimals = $currency->decimals ?? 3;

        $validator = Validator::make($request->all(), [
            'order_number'      => 'required',
            'billing_email'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $order = Order::select(DB::raw("CAST(ec_orders.amount AS DECIMAL(8, $decimals)) as amount"), DB::raw("CAST(ec_orders.sub_total AS DECIMAL(8, $decimals)) as sub_total"), DB::raw("CAST(ec_orders.tax_amount AS DECIMAL(8, $decimals)) as tax_amount"), 'ec_orders.id', 'ec_orders.code', 'ec_orders.status', 'ec_orders.shipping_amount', 'payments.payment_channel', 'ec_orders.created_at', 'ec_orders.service_amount', 'ec_orders.vat', 'payments.status AS payment_status')->join('ec_order_addresses', 'ec_order_addresses.order_id', 'ec_orders.id')->join('payments', 'payments.order_id', 'ec_orders.id')->where('ec_orders.code', $request->input('order_number'))->where('ec_order_addresses.email', $request->input('billing_email'))->first();

        if(!$order) {
            return response()->json(['message' => 'Order not found']);
        }

        $prod = OrderProduct::select('id', 'id as product_id', 'product_name', 'qty', 'price', 'order_id', 'is_gift', 'discount_percent', 'discount_amount', 'gross_amount', 'product_category')->where('ec_order_product.order_id', $order->id)->get();

        return response()->json([
            'message'          => 'Tracking Details Fetched successfully',
            'order_id'         => $order->code,
            'payment_method'   => $order->payment_channel,
            'total'            => $order->amount,
            'sub_total'        => $order->sub_total,
            'shipping_amount'  => $order->shipping_amount,
            'status'           => $order->status,
            'created_at'       => $order->created_at,
            'service_amount'   => $order->service_amount,
            'vat_amount'       => $order->vat,
            'tax_amount'       => $order->tax_amount,
            'payment_status'   => $order->payment_status,
            'products'         => $prod
        ]);
    }

    public function orderDetails(Request $request)
    {
        $currency = Currency::select('decimals')->where('is_default', 1)->first();
        $decimals = $currency->decimals ?? 3;

        $validator = Validator::make($request->all(), [
            'order_number'      => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $order = Order::select(DB::raw("CAST(ec_orders.amount AS DECIMAL(8, $decimals)) as amount"), DB::raw("CAST(ec_orders.sub_total AS DECIMAL(8, $decimals)) as sub_total"), DB::raw("CAST(ec_orders.tax_amount AS DECIMAL(8, $decimals)) as tax_amount"), 'ec_orders.id', 'ec_orders.code', 'ec_orders.status', 'ec_orders.shipping_amount', 'payments.payment_channel', 'ec_orders.created_at', 'ec_orders.service_amount', 'ec_orders.vat', 'payments.status AS payment_status')->join('ec_order_addresses', 'ec_order_addresses.order_id', 'ec_orders.id', 'left')->join('payments', 'payments.order_id', 'ec_orders.id', 'left')->where('ec_orders.code', $request->input('order_number'))->first();

        if(!$order) {
            return response()->json(['message' => 'Order not found']);
        }

        $prod = OrderProduct::select('id', 'id as product_id', 'product_name', 'qty', 'price', 'order_id', 'is_gift', 'discount_percent', 'discount_amount', 'gross_amount', 'product_category')->where('ec_order_product.order_id', $order->id)->get();

        return response()->json([
            'message'          => 'Details Fetched successfully',
            'order_id'         => $order->code,
            'payment_method'   => $order->payment_channel,
            'total'            => $order->amount,
            'sub_total'        => $order->sub_total,
            'shipping_amount'  => $order->shipping_amount,
            'status'           => $order->status,
            'created_at'       => $order->created_at,
            'service_amount'   => $order->service_amount,
            'vat_amount'       => $order->vat,
            'tax_amount'       => $order->tax_amount,
            'payment_status'   => $order->payment_status,
            'products'         => $prod
        ]);
    }

    public function validateCoupon(Request $request) {
         $validator = Validator::make($request->all(), [
            'couponCode'      => 'required',
            'mobile_number' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors());
        }

        $coupon = DiscountModel::where('code', $request->input('couponCode'))->where('start_date', '<=', now())->where('end_date', '>=', now())->first();

        if(!$coupon) {
            return response()->json(['message' => 'Invalid Coupon Code']);
        }

        $mobile_verification = MobileVerification::where('phone', $request->input('mobile_number'))->first();

        if(!$mobile_verification) {
            return response()->json(['message' => 'Verify Mobile Number First']);
        }

        $customer = Customer::where('phone', $request->input('mobile_number'))->first();

        if($customer) {
            $customer_discount = DB::table('ec_customer_used_coupons')->where('customer_id', $customer->id)->where('discount_id', $coupon->id)->first();
            if($customer_discount) {
                return response()->json(['message' => 'You Have Already Used this Coupon Code']);
            }
        }

        return response()->json([
            'message'          => 'Details Fetched successfully',
            'coupon'            => $coupon
        ]);
    }
}
