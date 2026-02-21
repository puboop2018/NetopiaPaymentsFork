## Introduction
The NETOPIA Payment PHP library provides easy access to the NETOPIA Payments API from
applications written in the PHP language.

## Compatible
PHP 8.1+

## API Documentation
* https://netopia-system.stoplight.io/docs/payments-api/6530c434c2f93-netopia-payments-merchant-api

## API Specification
* https://secure.sandbox.netopia-payments.com/spec

## Installation
To install the library via <a href="https://getcomposer.org/" target="_blank">composer</a>, run the following command:
* composer require netopia/payment2

Or add **"netopia/payment2": "^1.0.0"** to the "require" section in composer.json

## API Actions
### Start
Use this endpoint to start a payment. Based on the response to this call the process either stops or you need to continue with verify_auth

* **Endpoint:** /payment/card/start
* **Method:** `POST`    
* **Param type:** JSON
    * **Param structure:** The JSON as parameter has tree main part        
        * **Config:**  to set configiguraton section of a transaction
        * **Payment:** to set payment method and card informations of a transaction
        * **Order:** to set order details for a transction

* #### Sandbox URL:
       https://secure.sandbox.netopia-payments.com/payment/card/start   // Start endpoint URL - Sandbox
* #### Live URL:
       https://secure.mobilpay.ro/pay/payment/card/start                // Start endpoint URL - Live

* #### Steps for start
* #### **1) Define setting**
    ```php
        $request = new Request();
        $request->posSignature  = 'XXXX-XXXX-XXXX-XXXX-XXXX';                                 // Your signiture ID hear
        $request->apiKey        = 'ApiKey_GENERATE-YOUR-KEY-FROM-MobilPay-AND-USE-IT-HEAR';   // Your API key hear
        $request->isLive        = false;                                                      // false for SANDBOX & true for LIVE
        $request->notifyUrl     = 'http://your-domain.com/ipn.php';                           // Path of your IPN
        $request->redirectUrl   = null;
    ```
* #### **2) Make start json request**

    ```php
        $request->setRequest($configData, $cardData, $orderData, $threeDSecureData);
    ```
    * **Sample JSON:**

        ```
            {
                "config": {
                    "emailTemplate": "",
                    "notifyUrl": "http://your-domain/example/ipn.php",
                    "redirectUrl": "http://your-domain/example/backUrl.php",
                    "language": "RO"
                },
                "payment": {
                    "options": {
                        "installments": 1,
                        "bonus": 0
                    },
                    "instrument": {
                        "type": "card",
                        "account": "9900009184214768",
                        "expMonth": 11,
                        "expYear": 2025,
                        "secretCode": "111",
                        "token": null
                    },
                    "data": {
                        "BROWSER_USER_AGENT": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome",
                        "OS": "Linux",
                        "OS_VERSION": "x86_64",
                        "MOBILE": "false",
                        "SCREEN_POINT": "false",
                        "SCREEN_PRINT": "Current Resolution: 1920x1080, Available Resolution: 1920x1080, Color Depth: 24",
                        "BROWSER_COLOR_DEPTH": "24",
                        "BROWSER_SCREEN_HEIGHT": "1080",
                        "BROWSER_SCREEN_WIDTH": "1920",
                        "BROWSER_PLUGINS": "PDF Viewer, Chrome PDF Viewer, Chromium PDF Viewer, Microsoft Edge PDF Viewer",
                        "BROWSER_JAVA_ENABLED": "false",
                        "BROWSER_LANGUAGE": "en-US",
                        "BROWSER_TZ": "Europe/Bucharest",
                        "BROWSER_TZ_OFFSET": "-120",
                        "IP_ADDRESS": "37.221.166.134"
                    }
                },
                "order": {
                    "ntpID": "",
                    "posSignature": "1PD2-FYKC-R27B-55BW-NVGN",
                    "dateTime": "2022-11-21T14:13:13+02:00",
                    "description": "DEMO API FROM WEB - SDK",
                    "orderID": "R973i8Stza46n0me152oidgnr_492",
                    "amount": 159.9,
                    "currency": "RON",
                    "billing": {
                        "email": "clientemail84@5n3a4dmoi.com",
                        "phone": "9430715286",
                        "firstName": "Client prenume d21anr3",
                        "lastName": "Client nume nd21ar3",
                        "city": "Bucuresti",
                        "country": 642,
                        "state": "Bucuresti",
                        "postalCode": "246513",
                        "details": "Fara Detalie"
                    },
                    "shipping": {
                        "email": "clientemail84@5n3a4dmoi.com",
                        "phone": "9430715286",
                        "firstName": "Client prenume d21anr3",
                        "lastName": "Client nume nd21ar3",
                        "city": "Bucuresti",
                        "country": 642,
                        "state": "Bucuresti",
                        "postalCode": "246513",
                        "details": "Fara Detalie"
                    },
                    "products": [
                        {
                            "name": "T-shirt Alfa",
                            "code": "D276C05398EO14",
                            "category": "Fashion",
                            "price": 17,
                            "vat": 0
                        },
                        {
                            "name": "T-shirt Beta",
                            "code": "5E89D3C40O7126",
                            "category": "Fashion",
                            "price": 11,
                            "vat": 0
                        },
                        {
                            "name": "T-shirt Gamma",
                            "code": "D9E1826347OC50",
                            "category": "Fashion",
                            "price": 91,
                            "vat": 0
                        },
                        {
                            "name": "T-shirt Delta",
                            "code": "3574CE8102DO69",
                            "category": "Fashion",
                            "price": 40,
                            "vat": 0
                        }
                    ],
                    "installments": {
                        "selected": 1,
                        "available": [
                            0
                        ]
                    },
                    "data": null
                }
            }
        ```

* #### **3) Send your request**

    ```php
        $request->startPayment();
    ```
    
    * **Sample Start response:**

        The response of **START** endpoint, will be a Json with following structure

        ```
            {
            "status": 1,
            "code": 200,
            "message": "You send your request, successfully",
            "data": {
                    "customerAction": {
                    "authenticationToken": "0dDmWTelIV7SCTEH65t-rsfTqjZV39ihIBZud-AH_gz_DveTOjocRVUf-AflRAPYtAg5w13q3QgO6RyDIya",
                    "formData": {
                        "backUrl": "http://your-domain/example/backUrl.php",
                        "paReq": "E5bmIpX_RfopDI4uPbvT_ZBsS_hinKMZ8o5nuOPVOZU5F28vOwjDg4LyXzDQeVI="
                    },
                    "type": "Authentication3D",
                    "url": "https://secure.sandbox.netopia-payments.com/sandbox/authorize"
                    },
                    "error": {
                    "code": "100",
                    "message": "Approved"
                    },
                    "payment": {
                    "amount": 143.6,
                    "currency": "RON",
                    "ntpID": "1234567",
                    "status": 15
                    }
                }
            }
        ```
* #### **4) Analyzing Error Code**

    To continue payment progress, you will need to verify the error code in your app
        
    #### **Error** codes

    * **100** : Requires 3-D Secure authentication

    * **56**  : duplicated Order ID

    * **99**  : There is another order with a different price

    * **19**  : Expire Card Error

    * **20**  : Founduri Error

    * **21**  : CVV Error

    * **22**  : CVV Error

    * **34**  : Card Tranzactie nepermisa Error

    * **0**   : Card has no 3DS
        
    #### **Status** codes

    * **15** : need authorize
    * **3** : is paid
    * **5** : is confirmed

    for more information isit https://doc.netopia-payments.com/
    #### **Note** 
    Error Code **100** & Status **15**, means you need to do **Authorize** the transaction via Bank first (**3DS**)
    Error Code **0** & Status **3**, means your job is done.

    Base on other Error code you can handle the progress of payment .


* #### **5) Authorize 3D card**

    ##### What to send
    Find **parameters** to send from response of previous action on **data -> formData**
    ##### How to send
    For **authorize** of 3D card, you will need to send a HTTP request via **Form** by **POST** method to Bank authentication URL.
    ##### WHere to send
    you have the Bank authentication **URL** from response of previous action on **data -> customerAction -> url**
    
* #### **6) Verify authentication**
    To verify authentication you will need to send the request to **verify-auth** end point,

    * **Action URL:** /payment/card/verify-auth
    * **Method:** `POST`    
    * **Param type:** JSON
        * **Params:**         
            * **authenticationToken:**  The unique authentication token, from **start** action
            * **ntpID:** The transaction id from **start** action
            * **paRes:** The **DATA** from client's bank

    #### Example of verify-auth setting

    ```php
        $verifyAuth = new VerifyAuth();
        $verifyAuth->apiKey              = 'ApiKey_GENERATE-YOUR-KEY-FROM-MobilPay-AND-USE-IT-HEAR';
        $verifyAuth->authenticationToken = 'YOUR-UNIQUE-AUTHENTICATION-TOKEN-PER-REQUEST';
        $verifyAuth->ntpID               = 'THE-UNIQUE-TRANSACTION-ID';
        $verifyAuth->paRes               = 'THE-DATA-WHAT-YOU-RECIVE-IT-FROM-THE-BANK';
        $verifyAuth->isLive              = false;       // FALSE for SANDBOX & TRUE for LIVE mode
    ```

    #### make verify-auth json request

    ```php
        $jsonAuthParam = $verifyAuth->setVerifyAuth();  // To set parameters for /payment/card/verify-auth
    ```
    
    #### send verify-auth json request
    ```php
        $paymentResult = $verifyAuth->sendRequestVerifyAuth($jsonAuthParam);  // To send request to verify-auth
    ```

    #### Json ex. of verify-auth response
    ```
    {
    "authenticationToken": "YOUR-UNIQUE-AUTHENTICATION-TOKEN-PER-REQUEST",
    "ntpID": "1234567",
    "formData": 
        {
            "paRes": "THE-DATA-WHAT-YOU-RECIVE-IT-FROM-THE-BANK"
        }
    }
    ```

    #### verify-auth action response
    The response of **verify-auth** endpoint, will be a Json with following structure

    ```
    {
    "status": 1,
    "code": 200,
    "message": "Successfully verify authentication ",
    "data": {
        "error": {
        "code": "00",
        "message": "Approved"
        },
        "payment": {
        "amount": 141.7,
        "currency": "RON",
        "data": {
            "AuthCode": "4MHf",
            "BIN": "990000",
            "ISSUER": "Netopia GB",
            "ISSUER_COUNTRY": "642",
            "RRN": "m5kLj2HOLSfn"
        },
        "ntpID": "1234567",
        "status": 3,
        "token": "NTY1Mzq4mPwzwb4nynMLEfcwrA0MnEUJ/19Pk9doJWe5PWxoLhQC++W/Eqh6h/wB1KCDVSiBCkaWYtfeWFzWyFoP6YbS"
        }
    }
    }
    ```

    Regarding the **error code** & the **status** you will be able to manage the messages & the actions on your Site / App after the success or failed payments in 3DS

#### What is 3DS
3DS is a security protocol used to authenticate users / card holders.
*   #### What kind of data need to be collected for 3DS 
    To have benefit of 3DS need to be collected some simple data of the User's device, what they used it to make the payments 

    Like : 
        OS name, OS version, IP, ...
        
    
## Resources
#### ( <a href="https://github.com/netopiapayments" target="_blank">GitHub repository</a> )

## Changelog

#### v1.0.5 - 2025-09-17
* Change method of get token on IPN
#### v1.0.6 - 2025-11-03
* Upgrade JWT to ver ^6.0
* Update IPN to be compatibil with JWT ver ^6.0

## License

This plugin is licensed under the [MIT License](LICENSE).