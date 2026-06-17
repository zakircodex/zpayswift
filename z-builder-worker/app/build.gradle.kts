plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
}

val generatedAppName = providers.gradleProperty("zbuilderAppName").orElse("Z Builder Worker")
val generatedPackageName = providers.gradleProperty("zbuilderPackageName").orElse("com.zbuilder.worker")
val generatedApiBase = providers.gradleProperty("zbuilderApiBase").orElse("https://zpayswift.com")
val generatedAppId = providers.gradleProperty("zbuilderAppId").orElse("")
val generatedAppToken = providers.gradleProperty("zbuilderAppToken").orElse("")

android {
    namespace = generatedPackageName.get()
    compileSdk {
        version = release(36)
    }

    defaultConfig {
        applicationId = generatedPackageName.get()
        minSdk = 26
        targetSdk = 36
        versionCode = 1
        versionName = "1.0"

        manifestPlaceholders["appLabel"] = generatedAppName.get()
        buildConfigField("String", "ZB_APP_NAME", "\"${generatedAppName.get()}\"")
        buildConfigField("String", "ZB_API_BASE", "\"${generatedApiBase.get().trimEnd('/')}\"")
        buildConfigField("String", "ZB_WORKER_APP_ID", "\"${generatedAppId.get()}\"")
        buildConfigField("String", "ZB_WORKER_APP_TOKEN", "\"${generatedAppToken.get()}\"")

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    buildFeatures {
        buildConfig = true
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }
    kotlinOptions {
        jvmTarget = "11"
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.material)
    implementation(libs.androidx.activity)
    implementation(libs.androidx.constraintlayout)
    testImplementation(libs.junit)
    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.espresso.core)
}
