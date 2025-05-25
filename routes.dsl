let greet_person = (person) => {
    // Access MetaVar "person", extract name/age/city from nested fields if present
    $obj = $person->get();
    $name = $obj->get("name");
    $age = $obj->get("age");
    $city = $obj->get("city");
    // If the city is itself a nested MetaObject (e.g., address), support that too
    if ($city instanceof MetaObject) {
        $city = $city->get("name"); // or whatever field you use
    }
    return [
        "greeting" => "Hello $name, age $age" . ($city ? " from $city" : "")
    ];
};
tag greet_person as api, post, path:/api_v1/greet_person;


let add = (a, b) => $a + $b;
tag add as api, post, path:/api_v1/add;
debug off;

let greet_user = (name,age) => {
    if (empty($name->get())) {
        return ["error" => "Name is required"];
    }
    $greeting = new MetaVar("greeting", "Hello, " . ucfirst($name->get())." your age is ".ucfirst(age->get()));
    return ["status" => "success", "message" => $greeting->get()];
};


tag greet_user as api, get, path:/api_v1/greet_user;
