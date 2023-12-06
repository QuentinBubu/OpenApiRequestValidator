<?php
namespace OpenApiRequestValidator;

use Symfony\Component\Yaml\Yaml;
use Psr\Http\Message\ServerRequestInterface;

class ValidRequest
{
    public static ?ValidRequestError $error = null;

    /**
     * @param array $data
     * @param string $method
     * @return ValidRequestErrorEnum|bool
     */
    public static function check(array $data, ServerRequestInterface $request, string $yamlData): ValidRequestErrorEnum|bool
    {
        $method = strtolower($request->getMethod());
        $url = $request->getUri()->getPath();
        $url = strlen($url) > 1 ? rtrim($url, '/') : $url;
        $url = str_replace($data["prefix"] . "/" . $data["version"], "", $url);

        $spec = Yaml::parse($yamlData);

        // Check base url

        $exp = explode("/", $url);
        $designUrlReg = '/\/' . $exp[1];
        if (count($exp) > 2) {
            $designUrlReg .= '\/(.)*';
        } else {
            $designUrlReg .= '[^\/]*';
        }
        $designUrlReg .= '$/';



        $regRes = preg_grep($designUrlReg, array_keys($spec["paths"]));

        if ($regRes === [] || $regRes === false) {
            self::$error = new ValidRequestError(
                ValidRequestErrorEnum::BAD_URL,
                "Url not found"
            );
            return false;
        }

        if (count($regRes) > 1) {
            self::$error = new ValidRequestError(
                ValidRequestErrorEnum::BAD_URL,
                "Url conflict"
            );
            return false;
        }

        // Reset array keys
        $regRes = array_values($regRes);

        if (!isset($spec["paths"][$regRes[0]][$method])) {
            self::$error = new ValidRequestError(
                ValidRequestErrorEnum::BAD_METHOD,
                "Method not allowed"
            );
            return false;
        }

        $object = $spec["paths"][$regRes[0]][$method];

        // Check params
        foreach ($object["parameters"] as $param) {
            // Check if param is required and is in query
            if (
                isset($param['in'])
                && $param["in"] === "query"
                && !isset($request->getQueryParams()[$param["name"]])
                && $param["required"]
            ) {
                    self::$error = new ValidRequestError(
                        ValidRequestErrorEnum::BAD_REQUEST,
                        "Query param " . $param["name"] . " not found"
                    );
                    return false;
            }

            if (isset($param["in"])) {
                // Check type of param
                switch ($param['in']) {
                    case 'query':
                        if (
                            !self::checkTypes(
                                $request->getQueryParams()[$param["name"]],
                                $param["type"],
                                $param["name"],
                                "Query"
                                )
                            )
                            return false;
                        break;
                    case 'path':
                        if (
                            !self::checkTypes(
                                $exp[2],
                                $param["type"],
                                $param["name"],
                                "Path"
                                )
                            )
                            return false;
                        break;
                    case 'body':
                        if (
                            !self::checkStruct(
                                json_decode($request->getBody()->getContents(), true),
                                $spec,
                                $param["schema"],
                                $param["name"]
                                )
                            ) // recursive function
                            return false;
                        break;
                    default:
                        break;
                }
            }
        }

        // TD : "$ref": "#/parameters/Authorization" didn't have in parameter specified

        return true;
    }

    private static function checkTypes(mixed $data, string $type, string $name, string $el): bool
    {
        switch ($type) {
            case 'string':
                if (!is_string($data)) {
                    self::$error = new ValidRequestError(
                        ValidRequestErrorEnum::BAD_REQUEST,
                        $el . " param " . $name . " must be a string"
                    );
                    return false;
                }
                break;
            case 'integer' || 'number':
                if (!is_numeric($data)) {
                    self::$error = new ValidRequestError(
                        ValidRequestErrorEnum::BAD_REQUEST,
                        $el . " param " . $name . " must be a integer or numeric"
                    );
                    return false;
                }
                break;
            case 'boolean':
                if (!filter_var($data, FILTER_VALIDATE_BOOLEAN)) {
                    self::$error = new ValidRequestError(
                        ValidRequestErrorEnum::BAD_REQUEST,
                        $el . " param " . $name . " must be a boolean"
                    );
                    return false;
                }
                break;
            case 'array':
                if (!is_array($data)) {
                    self::$error = new ValidRequestError(
                        ValidRequestErrorEnum::BAD_REQUEST,
                        $el . " param " . $name . " must be an array"
                    );
                    return false;
                }
                break;
            default:
                break;
        }
        return true;
    }

    private static function checkStruct(mixed $data, array $baseSchema, array $schema, string $name): bool
    {
        // Transform $ref to schema
        if (array_key_exists('$ref', $schema))
            $schema = $baseSchema["definitions"][explode("/", $schema['$ref'])[2]];

        // Check if all required params are in body
        foreach ($schema["properties"] as $key => $value) {

            if ($key === '$ref') {
                if (!self::checkStruct($data, $baseSchema, $schema["definitions"][explode("/", $data[$key])[2]], $name))
                    return false;
                continue;
            }

            if (!isset($data[$key])) {
                self::$error = new ValidRequestError(
                    ValidRequestErrorEnum::BAD_REQUEST,
                    "Body param " . $key . " not found"
                );
                return false;
            }

            if (array_key_exists('$ref', $value)) {
                if (
                    !self::checkStruct(
                        $data[$key],
                        $baseSchema,
                        $baseSchema["definitions"][explode("/", $value['$ref'])[2]],
                        $key
                    )
                ) {
                    return false;
                }
                continue;
            }
            if (!self::checkTypes($data[$key], $value["type"], $key, "Body"))
                return false;
        }
        return true;
    }
}
