#!/usr/bin/env bash

red=`tput setaf 1`
green=`tput setaf 2`
reset=`tput sgr0`

mkdir -p target

echo -n "Введите платформу (m1/m2) "

read item
case "$item" in
    m1|M1) echo "=== Magento 1 ==="
        echo -n "Введите название класса. (Например, Mygento_Boxberry_Helper_Discount): "
        read class

        echo -n "Введите код модуля. (Например, boxberry): "
        read code

        result=`php generate.php -p=m1 --class="$class" --code="$code"`
        ;;
    m2|M2) echo "=== Magento 2 ==="
        result=`php generate.php -p=m2`
        ;;
    *) echo "Ничего не ввели. Генерим файл для Magento 2."
        result=`php generate.php -p=m2`
        ;;
esac

echo
echo "=== Formatting file ==="
php vendor/bin/phpcbf --standard=vendor/magento-ecg/coding-standard/Ecg,vendor/mygento/coding-standard/Mygento-Mage1  ./target/

if [ "$result" == "0" ]; then
    echo "${green}Файл успешно сгенерен. В папке ./target вас ждет свежий файл.${reset}"
else
    echo "${red}Ошибка генерации файла${reset}"
    echo "$result"
fi

